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
                // batch 4A
                { path: 'import', name: 'import', component: () => import('./views/ImportView.vue') },
                { path: 'scripts', name: 'scripts', component: () => import('./views/ScriptsView.vue') },
                // end batch 4A
                // batch 4B: automation and ops screens (single segments, so they never shadow :schema/:name)
                { path: 'queries', name: 'queries', component: () => import('./views/SavedQueriesView.vue') },
                { path: 'schedules', name: 'schedules', component: () => import('./views/SchedulesView.vue') },
                { path: 'backups', name: 'backups', component: () => import('./views/BackupsView.vue') },
                { path: 'sessions', name: 'sessions', component: () => import('./views/SessionsView.vue') },
                { path: 'audit', name: 'audit', component: () => import('./views/AuditView.vue') },
                // end batch 4B
                // A function is addressed by its signature, with ?fn=1. Tabs are ?tab=.
                { path: ':schema/:name', name: 'object', component: ObjectDetail },
            ],
        },
        { path: '/:pathMatch(.*)*', redirect: { name: 'home' } },
    ],
});

createApp(App).use(router).mount('#app');
