import './app.css';
import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import App from './App.vue';
import { basePath } from './config';
import HomeView from './views/HomeView.vue';

const router = createRouter({
    history: createWebHistory(`${basePath}/`),
    routes: [
        { path: '/', name: 'home', component: HomeView },
        { path: '/:pathMatch(.*)*', redirect: { name: 'home' } },
    ],
});

createApp(App).use(router).mount('#app');
