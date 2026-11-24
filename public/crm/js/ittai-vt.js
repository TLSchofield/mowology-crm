/* Native page transitions for the Ittai shell: a normal page load, with the sidebar drawn as one still picture and the
   content fading. No page swapping. The names are on only while a transition runs (a view-transition-name clips fixed children). */
(function () {
    var root = document.documentElement;
    function off() { root.classList.remove('it-vt'); }
    window.addEventListener('pageswap', function (e) { if (e.viewTransition) root.classList.add('it-vt'); });
    window.addEventListener('pagereveal', function (e) {
        if (!e.viewTransition) return;
        root.classList.add('it-vt');
        e.viewTransition.finished.then(off, off);
    });
})();
