const Home = () => import('./components/home.vue' /* webpackChunkName: "resource/js/components/welcome" */)

export const routes = [
    {
        name: 'home',
        path: '/',
        component: Home
    },   
]