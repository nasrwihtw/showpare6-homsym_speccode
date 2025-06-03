// <plugin root>/src/Resources/app/administration/src/module/swag-example/index.js
import deDE from '../../snippet/de-DE.json';
import enGB from '../../snippet/en-GB.json';

Shopware.Component.register('importcsv-speccode', () => import('../page/importcsv-speccode'));

Shopware.Module.register('homsym-importcsv-specode', {
    type: 'plugin',
    name: 'HomsymImportCsvSpecode',
    title: 'speccode.administration.menuItem',
    description: 'speccode.administration.menuItem.description',
    color: '#ff3d58',
    icon: 'default-shopping-paper-bag-product',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB
    },

    routes: {
        importcsvspeccode: {
            component: 'importcsv-speccode',
            path: 'importcsvspeccode'
        },
    },

    navigation: [{
        label: 'speccode.administration.navItem',
        color: '#ff3d58',
        path: 'homsym.importcsv.specode.importcsvspeccode',
        parent: 'sw-catalogue',
        icon: 'default-basic-stack',
        position: 100
    }]
});
