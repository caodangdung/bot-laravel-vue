<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Client;
use Illuminate\Support\Str;
use GrahamCampbell\GitLab\Facades\GitLab;
use Illuminate\Support\Facades\Http;

class TmnBot extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'TmnBot:cron';
    

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /* @var Client $client */
    protected $client;
    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(Client $client)
    {
        parent::__construct();
        $this->client = $client;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->connectImap();
        $this->info('TmnBot:cron run');
    }

    public function connectImap() 
    {       

        try {
            $client = Client::account("default");  
            $client->connect();  
  
            /** @var \Webklex\PHPIMAP\Support\FolderCollection $folders */  
            $folders = $client->getFolders(false, "Freedcamp+Gitlab");
            // $folders = $client->getFolders(false, "INBOX");  
  
            /** @var \Webklex\PHPIMAP\Folder $folder */  
            foreach($folders as $folder){  
                $this->info("Accessing folder: ".$folder->path);  
                  
                $messages = $folder->messages()->all()->since('02.04.2023')->fetchOrderDesc()->limit(3, 0)->get();
    
                $this->info("Number of messages: ".$messages->count());  
                
                /** @var \Webklex\PHPIMAP\Message $message */  
                foreach ($messages as $message) {                                                                             
                    $this->processEmall($message); 
                }  
            }
            $this->info('connectImap success');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function processEmall($message) {       
        $uid = $message->getUid();
        $from = $message->getFrom();
        $subject = $message->getSubject();
        $content = $message->getRawBody();

        $content = Str::replace("=\r\n", "g", $content);
        // $this->info("\tMessage uid: ".$uid);
        $this->info("\tMessage from: ".$from);
        // $this->info("\tMessage subject: ".$subject);
        // $this->info("\tMessage body: ".$content);
        
        $message = [
            "UID" => $uid,
            "from" => $from,
            "subject" => $subject,
            "content" => $content            
        ];                
        
        if (Str::contains($from, "gitlab.com")) {            
            $this->processGitlabEmail($message);
        } else if (Str::contains($from, "freedcamp.com")) {
            $this->processFreedcampEmail($message);
        }
    }

    public function processGitlabEmail($message) {        
        $content = $message['content'];        
        $ids = $this->scanMR($content);   
        
        $ids->map(function($item) {
            $projectPath = $item[0];
            $id = $item[1];            
            $mergeRequest = $this->getMergeRequest($projectPath, $id);
                          
            if ($mergeRequest) {                                
                $title = $mergeRequest['title'];
                $description = $mergeRequest['description'];
                  
                $taskIds = $this->scanIssueIds($title.$description);

                $taskIds->map(function($item) use ($mergeRequest, $projectPath, $id) {
                    $taskId = $item;
                    $comment = "";
                    $author = $mergeRequest['author'];
                    $mergeBy = $mergeRequest['merge_by'];
                    $state = $mergeRequest['state'];

                    switch ($state) {
                        case 'opened':
                            $name = $author['name'];
                            $username = $author['username'];
                            $comment = <<<COMMENT
                                $name ($username) vừa tạo MR mới cho task này.
                                <br/>
                                <br/><strong>Source branch</strong>: $mergeRequest->source_branch
                                <br/><strong>Target branch</strong>: $mergeRequest->target_branch
                                COMMENT;
                            break;
                        case 'merged': 
                            $name = $mergeBy['name'];
                            $username = $mergeBy['username'];
                            $comment = <<<COMMENT
                                    $mergeBy->name ($mergeBy->username) đã merge MR cho task này.
                                COMMENT;
                            break;
                        default:                        
                            break;
                    }

                    $webUrl = $mergeRequest['web_url'];
                    $comment .= "<br/>".$webUrl;

                    $task = $this->getFreedcampTask($taskId);    
                    if ($task) {
                        $comments = $task->comments;
                        $commentedBefore = false;

                        foreach ($comments as $key => $value) {
                            $description = $value->description;
                            if (Str::contains($description, $webUrl)) {
                                $commentedBefore = true;
                            }
                        }

                        if (!$commentedBefore) {
                            $this->postMergeRequestComment($projectPath, $id, $comment);
                        }

                        if (!$commentedBefore || $state === "merged") {                                               
                            $this->addCommentToTask($taskId, $comment);
                        }                        
                    }                                                     
                });                
            }              
        });        
        
        $message->setFlags(['\\SEEN']);
    }

    public function processFreedcampEmail($message) {
        $content = $message['content'];        
        $ids = $this->lookupTaskIds($content);   
        
        $ids->map(function($item) {            
            $id = $item;                                   
            $task = $this->getFreedcampTask($id);
            if ($task) {                   
                $taskId = $task->id;
                $taskTitle = $task->title;

                if ($this->getIdFromTitle($taskTitle) !== "") {
                    $this->setTitleForTask($taskId, "#".$taskId . " " . $taskTitle);
                }
            }            
        });        
        
        $message->setFlags(['\\SEEN']);
    }

    // Git lab
    public function getMergeRequest($projectPath, $id) {
        $this->info("getMergeRequest: " . $projectPath. " ".$id);   
        \Log::info("getMergeRequest: " . $projectPath. " ".$id);         
        $mergeRequest = GitLab::connection()->mergeRequests()->show($projectPath, $id);         
        $this->info("getMergeRequest: " . $mergeRequest['title']);     
        return $mergeRequest;      
    }

    public function postMergeRequestComment($projectPath, $id, $comment) {       
        $mergeRequest = GitLab::connection()->mergeRequests()->addNote($projectPath, $id, $comment);
        return $mergeRequest;      
    }

    // Freedcamp
    public function getFreedcampToken() {
        $secret = env("FREEDCAMP_SECRET", "");
        $publicKey = env("FREEDCAMP_KEY", "");
        $timestamp = now()->timestamp;
        $hash = hash_hmac('sha1', $publicKey.$timestamp, $secret);
        
        $token = [
            "api_key" => $publicKey,
            "timestamp" => $timestamp,
            "hash" => $hash
        ];

        return $token;
    }

    public function getFreedcampTask($taskId) {
        $token = $this->getFreedcampToken();
        $params = array_merge([
            "f_cf" => 1
        ], $token);
        
        $this->info($params['hash']."\n".$params['timestamp']);
        
        $task = Http::get("https://freedcamp.com/api/v1/tasks/".$taskId, $params);
        $task = json_decode($task);

        $task = $task->data->tasks[0] ?? "";
        
        return $task;
    }

    public function addCommentToTask($taskId, $comment) {
        $token = $this->getFreedcampToken();
         
        $params = [
            "item_id" => $taskId,
            "description" => $comment,
            "app_id" => 2
        ];

        $params = "data=".urlencode(json_encode($params));
        $task = Http::withUrlParameters([
           'data' => $params
        ])->asForm()->post("https://freedcamp.com/api/v1/comments", $token);

        return $task;
    }

    public function setTitleForTask($taskId, $taskTitle) {
        $token = $this->getFreedcampToken();
         
        $params = [
            "title" => $taskTitle,           
        ];
                
        $task = Http::withUrlParameters([
            'data' => $params
         ])->asForm()->post("https://freedcamp.com/api/v1/tasks/".$taskId, $token);

        return $task;
    }

    public function scanMR($content) {
        $regex = "/https:\/\/gitlab.com\/([\w-]+\/[\w-]+).*?\/merge_requests\/(\d+)/im";        
        $ids = collect([]);
        $distinct = collect([]);

        $numberMatches = preg_match_all($regex, $content, $matches);
       
        if ($numberMatches !== false) { 
            for ($i=0; $i < $numberMatches; $i++) { 
                $projectPath = $matches[1][$i];
                $id = $matches[2][$i];     
                
                if (!$distinct->contains($id)) {                                             
                $ids->push([$projectPath, $id]);
                    $distinct->push($id);                    
                }                
            }                           
        }       
        return $ids;
    }

    public function scanIssueIds($content) {
        $regex = "/#(\d+)/im";        
        $ids = collect([]);
        $distinct = collect([]);

        $numberMatches = preg_match_all($regex, $content, $matches);
       
        if ($numberMatches !== false) { 
            for ($i=0; $i < $numberMatches; $i++) {                 
                $id = $matches[1][$i];     
                
                if (!$distinct->contains($id)) {                                             
                    $ids->push($id);
                    $distinct->push($id);                    
                }                
            }                           
        }       
        return $ids;
    }

    public function lookupTaskIds($content) {
        $regex = "/https:\/\/.*\/todos\/(\d+)/im";        
        $ids = collect([]);
        $distinct = collect([]);

        $numberMatches = preg_match_all($regex, $content, $matches);
       
        if ($numberMatches !== false) { 
            for ($i=0; $i < $numberMatches; $i++) {                 
                $id = $matches[1][$i];     
                
                if (!$distinct->contains($id)) {                                             
                    $ids->push($id);
                    $distinct->push($id);                    
                }                
            }                           
        }       
        return $ids;
    }

    public function getIdFromTitle($title) {
        $regex = "/^#(\d+) .*/im";        
        $ids = collect([]);
        $distinct = collect([]);

        $numberMatches = preg_match_all($regex, $title, $matches);
       
        if ($numberMatches !== false) { 
            for ($i=0; $i < $numberMatches; $i++) {                 
                $id = $matches[1][$i];     
                
                if (!$distinct->contains($id)) {                                             
                    $ids->push($id);
                    $distinct->push($id);                    
                }                
            }                           
        }      
        
        if ($ids->count() > 0) {
            return $ids->last();
        }

        return "";
    }

    public function getProjectIdFromCustomFieldValue($content) {
        $regex = "/^(\d+).*/im";        
        $ids = collect([]);
        $distinct = collect([]);

        $numberMatches = preg_match_all($regex, $content, $matches);
       
        if ($numberMatches !== false) { 
            for ($i=0; $i < $numberMatches; $i++) {                 
                $id = $matches[1][$i];     
                
                if (!$distinct->contains($id)) {                                             
                    $ids->push($id);
                    $distinct->push($id);                    
                }                
            }                           
        }      
        
        if ($ids->count() > 0) {
            return $ids->last();
        }

        return "";
    }
}