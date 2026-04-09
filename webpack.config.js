const Encore = require('@symfony/webpack-encore');

// Encore.isProduction() returns true when NODE_ENV=production
const isProduction = Encore.isProduction();

Encore
    // the project directory where compiled assets will be stored
    .setOutputPath('public/build/')
    // the public path used by the web server to access the previous directory
    .setPublicPath('/build')

    // will create public/build/app.js and public/build/app.css - or more files if using dynamic imports
    .addEntry('app', './assets/app.js')

    // enable the Symfony UX Stimulus bridge (used in assets/bootstrap.js)
    // .enableStimulusBridge('./assets/bootstrap.js')

    // splits your code into smaller files
    // tip: only add to this list items that are in node_modules
    .splitEntryChunks()

    // will require an extra script tag for runtime.js
    // but, you will be able to leverage caching better. However, as
    // you require more and more of your modules, webpack might create
    // multiple runtime files, so you'll probably want the default now
    // (which creates a single runtime file). You should enable this again
    // when you have very large entry files on-demand or care deeply about
    // initial download size. For normal applications this isn't needed.
    // .enableSingleRuntimeChunk()

    // will require you to manually update your webpack.config.js
    // to use .loadersConfigurationFromConfig() instead
    .cleanupOutputBeforeBuild()
    .enableSingleRuntimeChunk()
    .enableSourceMaps(!isProduction)

    // enables hashed filenames (e.g. app.abc123.js)
    .enableVersioning(isProduction)

    // configure Babel
    .configureBabelPresetEnv((config) => {
        config.useBuiltIns = 'entry';
        config.corejs = '3.4';
    })

    // Enable Vue Loader
    .enableVueLoader()
;

module.exports = Encore.getWebpackConfig();
