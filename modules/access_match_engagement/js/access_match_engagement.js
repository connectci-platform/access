/* Hide notes box until Coordinator checks the box to send a note */
var notes = document.getElementById("edit-field-notes-to-author-wrapper");
if (notes) {
  notes.classList.add("hide");
}

var checkBoxEmail = document.getElementById("edit-field-email-user-value");
if (checkBoxEmail) {
  checkBoxEmail.onclick = function () {
    notes.classList.toggle("hide");
  };
}
