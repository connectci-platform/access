(function (Drupal) {
  Drupal.behaviors.researcherStoriesReadMore = {
    attach(context) {
      context.querySelectorAll('.project-summary:not(.read-more-processed)').forEach((el) => {
        el.classList.add('read-more-processed');

        const id = `project-summary-${Math.floor(Math.random() * 1000000)}`;
        el.id = id;
        el.style.scrollMarginTop = '25px';

        const link = document.createElement('a');
        link.href = `#${id}`;
        link.className = 'read-more-toggle';
        link.textContent = 'Read More';

        link.addEventListener('click', (e) => {
          if (link.textContent === 'Read More') {
            el.style.maxHeight = 'inherit';
            link.textContent = 'Read Less';
          } else {
            el.style.maxHeight = '200px';
            link.textContent = 'Read More';
          }
        });

        el.after(link);
      });
    },
  };
}(Drupal));
