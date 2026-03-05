var item = document.querySelectorAll('.cssn-directory-item');
item.forEach(addCount);
function addCount(item) {
  // count .square-tag li inside index
  var squareTags = item.querySelectorAll('.square-tags li').length;
  if (squareTags > 5) {
    var squareTags = squareTags - 5;
    var more = "+ " + squareTags + " more";
    item.querySelector('.square-tags li:last-child').innerHTML = more;
    item.querySelector('.square-tags li:last-child').style.display = "flex";
    item.querySelector('.square-tags li:last-child').style.alignItems = "center";
    item.querySelector('.square-tags li:last-child').style.fontSize = "14px";
  }
}
