import PublishPage from './pages/Publish.vue';
import SsgPublishWidget from './components/SsgPublishWidget.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('ssg-publish::Publish', PublishPage);
    Statamic.$components.register('ssg-publish-widget', SsgPublishWidget);
});
