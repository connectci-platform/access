(function ($, Drupal, once) {
  "use strict";
  Drupal.behaviors.peopleDirectory = {
    attach: function (context, settings) {

      var item = document.querySelectorAll('.user-skills');
      item.forEach(addCount);
      function addCount(item) {
        // count .square-tag li inside index
        var squareTags = item.querySelectorAll('a').length;
        if (squareTags > 5 && item.querySelector('span') == NULL) {
          var squareTags = squareTags - 5;
          var more = "+ " + squareTags + " more";
          // Create new span element
          var span = document.createElement("span");
          // Add style.display flex to span.
          span.style.display = "flex";
          span.style.alignItems = "center";
          span.style.fontSize = "14px";
          // Add more text to span.
          span.innerHTML = more;
          item.appendChild(span);
        }
      }

    }
  };
})(jQuery, Drupal, once);
