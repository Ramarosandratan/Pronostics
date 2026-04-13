import { createRouter, createWebHistory } from 'vue-router';
import Home from '../views/Home.vue';
import RaceDetails from '../views/RaceDetails.vue';

const routes = [
    {
        path: '/',
        name: 'Home',
        component: Home
    },
    {
        path: '/race/:id',
        name: 'RaceDetails',
        component: RaceDetails
    }
];

const router = createRouter({
    history: createWebHistory('/'),
    routes
});

export default router;
