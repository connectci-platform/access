// Get all elements with the class "flag-upvote a"
const flagUpvoteElements = document.querySelectorAll('.flag-upvote a');

// Loop through each element and add the class "text-decoration-none"
flagUpvoteElements.forEach(element => {
  element.classList.add('text-decoration-none', 'fw-normal');
});
