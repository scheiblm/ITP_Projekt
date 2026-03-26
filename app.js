document.querySelectorAll('form').forEach((form) => {
  form.addEventListener('submit', (event) => {
    const action = form.querySelector('input[name="action"]')?.value;
    if (action === 'mark_done' && !confirm('Patient wirklich als erledigt markieren?')) {
      event.preventDefault();
    }
  });
});
