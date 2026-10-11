(() => {
  const root=document.getElementById('cinematic'); if(!root) return;
  const reduced=window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const scenes=[...root.querySelectorAll('.scene')], caption=document.getElementById('scene-caption');
  const labels=['01 / CAMPUS OVERVIEW','02 / APPROACHING THE BUILDING','03 / INSIDE THE CLASSROOM'];
  let active=false, finished=false, progress=0, ticking=false;
  const start=()=>{if(finished)return;active=true;document.body.classList.add('intro-running');window.scrollTo(0,0);render();};
  const finish=()=>{finished=true;active=false;document.body.classList.remove('intro-running');root.style.display='none';document.documentElement.style.scrollBehavior='smooth';window.removeEventListener('wheel',wheel);window.removeEventListener('touchmove',touch);window.removeEventListener('keydown',keys);};
  function render(){const step=Math.min(2,Math.floor(progress*3));scenes.forEach((scene,i)=>{const local=Math.max(0,Math.min(1,(progress-i/3)*3));scene.style.opacity=i===step?'1':(i===step-1?'0':'0');scene.style.transform=`scale(${1.02+local*.24}) translate3d(0,${-local*2}%,0)`;});caption.textContent=labels[step];const copy=root.querySelector('.cinematic-copy');copy.style.opacity=progress>.88?String(Math.max(0,(1-progress)/.12)):'1';if(progress>=1)finish();}
  function advance(amount){if(!active)return;progress=Math.max(0,Math.min(1,progress+amount));render();}
  function wheel(e){if(finished)return;if(!active)start();e.preventDefault();if(!ticking){ticking=true;requestAnimationFrame(()=>{advance(Math.max(-.025,Math.min(.025,e.deltaY/1800)));ticking=false;});}}
  let lastTouch=0;function touch(e){if(finished)return;if(!active)start();const y=e.touches?.[0]?.clientY??0;if(lastTouch)e.preventDefault();if(lastTouch)advance((lastTouch-y)/1800);lastTouch=y;}
  function keys(e){if(!active)return;if(['ArrowDown','PageDown',' '].includes(e.key)){e.preventDefault();advance(.12);}else if(['ArrowUp','PageUp'].includes(e.key)){e.preventDefault();advance(-.12);}else if(e.key==='Escape')finish();}
  document.getElementById('enter-portal')?.addEventListener('click',()=>{if(!active)start();advance(.34);});document.getElementById('skip-intro')?.addEventListener('click',finish);
  if(reduced){root.style.display='none';return;}
  // First visit only: don't replay after the user has entered the portal in this browser session.
  try{if(sessionStorage.getItem('careerIntroSeen')==='1'){root.style.display='none';return;}sessionStorage.setItem('careerIntroSeen','1');}catch(_e){}
  window.addEventListener('wheel',wheel,{passive:false});window.addEventListener('touchmove',touch,{passive:false});window.addEventListener('keydown',keys);
  // Intro is an optional interaction; regular content remains available immediately below it.
  document.documentElement.style.scrollBehavior='auto';
})();
