function copyclip(url, event) {
  navigator.clipboard.writeText(url);

  // Get the button that was clicked.
  var clickedButton = event.currentTarget;

  // Get the span elements within the clicked button.
  var copyDefault = clickedButton.parentElement.parentElement.querySelector('.default-message');
  var copySuccess = clickedButton.parentElement.parentElement.querySelector('.copied-message');

  // Add 'hidden' class to the default message and remove it from the success message.
  copyDefault.classList.add('hidden', 'd-none');
  copySuccess.classList.remove('hidden', 'd-none')
  // After 3 seconds, remove the 'hidden' class from the default message and add it to the success message.
  setTimeout(function () {
    copyDefault.classList.remove('hidden', 'd-none');
    copySuccess.classList.add('hidden', 'd-none');
  }, 6000);
}
