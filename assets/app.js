import './styles/app.css';
import { createApp } from 'vue';
import App from './components/App.vue';

// Créer l'instance Vue et la monter sur l'élément #app
const app = createApp(App);
app.mount('#app');
