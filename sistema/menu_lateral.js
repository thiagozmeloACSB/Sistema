/* Menu lateral Opa: transforma a barra de abas (ou .ml-nav) em menu lateral. Sem dependências. */
(function(){
  function init(){
    var nav=document.querySelector('.tabs-nav, .nav-tabs, .ml-nav');
    if(!nav||document.body.classList.contains('ml-on'))return;
    nav.classList.add('ml-side');
    document.body.classList.add('ml-on');
    var header=document.querySelector('body>header');
    if(header){
      var h=header.getBoundingClientRect().height;
      if(h>0)document.documentElement.style.setProperty('--ml-top',Math.round(h)+'px');
    }
    var back=document.createElement('div');back.className='ml-back';document.body.appendChild(back);
    var btn=document.createElement('button');btn.type='button';btn.className='ml-toggle';
    btn.setAttribute('aria-label','Abrir menu');btn.setAttribute('aria-expanded','false');btn.textContent='☰';
    if(header)header.insertBefore(btn,header.firstChild);
    function abrir(v){document.body.classList.toggle('ml-open',v);btn.setAttribute('aria-expanded',v?'true':'false');}
    btn.addEventListener('click',function(){abrir(!document.body.classList.contains('ml-open'));});
    back.addEventListener('click',function(){abrir(false);});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')abrir(false);});
    nav.addEventListener('click',function(e){if(e.target.closest('button,a'))abrir(false);});
    window.addEventListener('resize',function(){if(window.innerWidth>=900)abrir(false);});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
