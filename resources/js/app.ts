import './app.css';
import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import App from './App.vue';
import { basePath } from './config';
import ConnectionHome from './views/ConnectionHome.vue';
import ConnectionLayout from './views/ConnectionLayout.vue';
import HistoryView from './views/HistoryView.vue';
import HomeView from './views/HomeView.vue';
import ObjectDetail from './views/ObjectDetail.vue';
import RunsView from './views/RunsView.vue';

const router = createRouter({
    history: createWebHistory(`${basePath}/`),
    routes: [
        { path: '/', name: 'home', component: HomeView },
        {
            path: '/:label',
            component: ConnectionLayout,
            children: [
                { path: '', name: 'connection', component: ConnectionHome },
                // CodeMirror is only loaded once someone opens the editor.
                { path: 'sql', name: 'sql', component: () => import('./views/SqlEditorView.vue') },
                { path: 'history', name: 'history', component: HistoryView },
                { path: 'runs', name: 'runs', component: RunsView },
                // A function is addressed by its signature, with ?fn=1. Tabs are ?tab=.
                { path: ':schema/:name', name: 'object', component: ObjectDetail },
            ],
        },
        { path: '/:pathMatch(.*)*', redirect: { name: 'home' } },
    ],
});

createApp(App).use(router).mount('#app');
