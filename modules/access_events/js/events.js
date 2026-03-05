// Remove em from title.
let h1 = document. querySelector('h1');
h1.textContent = h1.textContent;
// rewrite h1 without the <em> tag.
h1.innerHTML = h1.textContent;

// Rename Body to Description.
let body = document.querySelector('[for="edit-body-0-value"]');
body.textContent = body.textContent;
body.innerHTML = 'Description';

// Rename label to Single Event.
let single = document.querySelector('[for="edit-recur-type-custom"]');
single.textContent = single.textContent;
single.innerHTML = 'Single Event';

// Select 'single event' radio button if no radio button is checked.
const radioButtons = document.querySelectorAll('input[name="recur_type"]');
radioSelect = 1;
for (const radioButton of radioButtons) {
  // check if the radio button is checked
  if (radioButton.checked) {
    radioSelect = 0;
    break;
  }
}
if (radioSelect) {
  document.getElementById("edit-recur-type-custom").checked = TRUE;
}

// Overwrite text in #edit-title-0-value--description to 'Event Title'.
let title = document.querySelector('#edit-title-0-value--description');
title.textContent = title.textContent;
title.innerHTML = 'The title of your event. Please do not include date or location information in the title since that is listed elsewhere in the event.';

// Overwrite text in # to 'Event Title'.
let virtualTitle = document.querySelector('#edit-field-event-virtual-meeting-link-wrapper label');
virtualTitle.insertAdjacentHTML('afterend', '<div class="form-item__description description form-item__description--label-help">Provide link to virtual meeting. If there is one.</div>');

let registration = document.querySelector('#edit-event-registration-0-registration');

// Initial check on page load.
checkRegistration();

registration.addEventListener('change', function () {
  checkRegistration();
});

function checkRegistration() {
  if (registration.checked) {
    // Hide 'use external registration' text field.
    document.getElementById("edit-field-registration-0-uri").value = 'http://example.com';
    document.getElementById("edit-field-registration-wrapper").style.display = 'none';
    document.getElementById("edit-field-event-allocation-grant-wrapper").style.display = 'block';
    // Show survey details wrappers.
    document.getElementById("edit-group-screening-survey").style.display = 'block';
    document.getElementById("edit-group-surveys").style.display = 'block';
    document.getElementById("edit-group-post-survey").style.display = 'block';
  } else {
    document.getElementById("edit-field-registration-0-uri").value = '';
    document.getElementById("edit-field-registration-wrapper").style.display = 'block';
    document.getElementById("edit-field-event-allocation-grant-wrapper").style.display = 'none';
    // Hide survey details wrappers.
    document.getElementById("edit-group-screening-survey").style.display = 'none';
    document.getElementById("edit-group-surveys").style.display = 'none';
    document.getElementById("edit-group-post-survey").style.display = 'none';
  }
}
