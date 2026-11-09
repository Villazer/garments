/* Session 3: clicking a thumbnail swaps the main photo. Without JavaScript the thumbnail simply opens the image. */
document.addEventListener('DOMContentLoaded', function () {
  var main = document.querySelector('[data-gallery-main]');
  if (!main) { return; }
  document.querySelectorAll('[data-gallery-thumb]').forEach(function (thumb) {
    thumb.addEventListener('click', function (event) {
      event.preventDefault();
      var img = main.querySelector('img') || main.appendChild(document.createElement('img'));
      img.src = thumb.getAttribute('href');
      img.alt = thumb.getAttribute('data-alt') || '';
    });
  });
});
