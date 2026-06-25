import axios from 'axios';

const loadMoreEl = document.getElementById('js-load-more-trigger');
let cancelToken;

if (loadMoreEl) {
    loadMoreEl.addEventListener('click', loadMore);
}

function loadMore(e) {
    const target = loadMoreEl.dataset.target;
    const targetEl = document.getElementById(target);

    [...targetEl.getElementsByClassName('job')].forEach(function(job) {
        job.classList.remove('d-none');
        job.classList.add('d-flex');
    });

    e.currentTarget.classList.add('d-none');
}
