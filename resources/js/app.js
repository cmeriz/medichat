import './bootstrap';
import '../css/app.css';
import 'element-plus/dist/index.css';

import { createApp } from 'vue';
import ElementPlus from 'element-plus';
import HomeApp from './components/HomeApp.vue';

createApp(HomeApp).use(ElementPlus).mount('#app');
