const zip = require("gulp-zip")
const gulp = require('gulp')
const composer = require("gulp-composer")

async function cleanTask() {
    const del = await import("del")
    return del.deleteAsync('./dist/plugin/**', {force:true});
}

function moveMediaFolderTask() {
    return gulp.src([
        './media/plg_system_aesirx_sso/**',
        '!./media/plg_system_aesirx_sso/src/**'
    ]).pipe(gulp.dest('./dist/plugin/media'))
}

function movePluginFolderTask() {
    return gulp.src([
        './plugins/system/aesirx_sso/**',
    ]).pipe(gulp.dest('./dist/plugin'))
}

function compressTask() {
    return gulp.src('./dist/plugin/**')
        .pipe(zip('plg_system_aesirx_sso.zip'))
        .pipe(gulp.dest('./dist'));
}

function composerTask() {
    return composer({
        "working-dir": "./dist/plugin"
    })
}

async function cleanComposerTask() {
    const del = await import("del")
    return del.deleteAsync('./dist/plugin/composer.*', {force:true});
}

exports.zip = gulp.series(
    cleanTask,
    gulp.parallel(
        moveMediaFolderTask,
        movePluginFolderTask
    ),
    composerTask,
    cleanComposerTask,
    compressTask,
    cleanTask
);
