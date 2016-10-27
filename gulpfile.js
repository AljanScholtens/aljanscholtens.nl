var gulp = require('gulp')
var sourcemaps = require('gulp-sourcemaps')
var postcss = require('gulp-postcss')
var cssnano = require('gulp-cssnano')
var atImport = require('postcss-import')
var lost = require('lost')
var cssnext = require('postcss-cssnext')
var nunjucks = require('gulp-nunjucks')
var browserSync = require('browser-sync').create()

gulp.task('default', ['serve'])

gulp.task('serve', ['build', 'watch'], function() {
  browserSync.init({
    server: {
      baseDir: "./dist/"
    },
    open: false
  });
  gulp.watch('dist/**/*').on('change', browserSync.reload)
})

gulp.task('watch', function() {
  gulp.watch('src/**/*.html', ['html'])
  gulp.watch('src/assets/stylesheets/**/*.css', ['css'])
  gulp.watch('src/assets/images/**/*', ['images'])
  gulp.watch('src/assets/scripts/**/*', ['scripts'])
})

gulp.task('build', ['html', 'css', 'scripts', 'images'])

gulp.task('html', () =>
  gulp.src('src/*.html')
   .pipe(nunjucks.compile())
   .pipe(gulp.dest('dist'))
)

gulp.task('images', () =>
  gulp.src('src/assets/images/*')
    .pipe(gulp.dest('dist/assets/images'))
)

gulp.task('scripts', () =>
  gulp.src('src/assets/scripts/*')
    .pipe(gulp.dest('dist/assets/scripts'))
)

gulp.task("css", function() {
  var processors = [
    atImport,
    cssnext(),
    lost()
  ]
  gulp.src(['src/assets/stylesheets/styles.css'])
    .pipe(sourcemaps.init())
    .pipe(postcss(processors))
    .pipe(cssnano())
    .pipe(sourcemaps.write('.'))
    .pipe(gulp.dest('dist/assets/stylesheets'))
})
