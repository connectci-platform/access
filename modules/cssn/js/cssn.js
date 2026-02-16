function bioMore() {
  const bio = document.getElementById('full-bio');
  const summary = document.getElementById('bio-summary');
  const moreBtn = document.getElementById('bio-more');
  const lessBtn = document.getElementById('bio-less');
  // Show full bio
  bio.classList.remove('sr-only');
  bio.setAttribute('aria-hidden', 'false');
  // Hide summary
  summary.classList.add('hidden');
  summary.setAttribute('aria-hidden', 'true');
  // Update button states
  if (moreBtn) moreBtn.setAttribute('aria-expanded', 'true');
  if (lessBtn) lessBtn.setAttribute('aria-expanded', 'true');
  // Move focus to full bio for screen readers
  if (lessBtn) lessBtn.focus();
}

function bioLess() {
  const bio = document.getElementById('full-bio');
  const summary = document.getElementById('bio-summary');
  const moreBtn = document.getElementById('bio-more');
  const lessBtn = document.getElementById('bio-less');
  // Hide full bio
  bio.classList.add('sr-only');
  bio.setAttribute('aria-hidden', 'true');
  // Show summary
  summary.classList.remove('hidden');
  summary.setAttribute('aria-hidden', 'false');
  // Update button states
  if (moreBtn) moreBtn.setAttribute('aria-expanded', 'false');
  if (lessBtn) lessBtn.setAttribute('aria-expanded', 'false');
  // Move focus back to more button
  if (moreBtn) moreBtn.focus();
}

// Add the button to the bio summary on user profile.
setTimeout(function () {
  const summaryElement = document.querySelector('.user-profile-view #bio-summary.more');
  const fullBioElement = document.querySelector('.user-profile-view #full-bio');

  // Only proceed if both elements exist
  if (summaryElement && fullBioElement) {
    summaryElement.innerHTML += '<button id=\'bio-more\' onclick=\'bioMore()\' style=\'border-width: 0 !important;\' class=\'btn btn-primary p-3\' type=\'button\' aria-expanded=\'false\' aria-controls=\'full-bio\'><i class=\'bi-chevron-down\' aria-hidden=\'true\'></i> More</button>';
    fullBioElement.innerHTML += '<button id=\'bio-less\' onclick=\'bioLess()\' style=\'border-width: 0 !important;\' class=\'btn btn-primary p-3\' type=\'button\' aria-expanded=\'true\' aria-controls=\'full-bio\'><i class=\'bi-chevron-up\' aria-hidden=\'true\'></i> Less</button>';
  }
}, 500);
