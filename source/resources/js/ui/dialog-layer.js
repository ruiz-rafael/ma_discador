// One stack owns focus and background isolation, including nested dialogs.
const layers=[]
const state=new WeakMap()
let isolated=new Map(),overflow=''
const focusable=el=>[...el.querySelectorAll('button,input,select,textarea,a[href],summary,[tabindex]')].filter(e=>!e.matches(':disabled,[tabindex="-1"]')&&!e.closest('[inert]')&&e.getClientRects().length)
function focus(el){(focusable(el)[0]||el).focus({preventScroll:true})}
function isolate(){
 for(const [el,value]of isolated)el.inert=value
 isolated.clear()
 const top=layers.at(-1)
 if(!top)return
 let branch=top
 while(branch.parentElement){
  for(const sibling of branch.parentElement.children)if(sibling!==branch&&sibling instanceof HTMLElement&&!['SCRIPT','STYLE','LINK'].includes(sibling.tagName)){isolated.set(sibling,sibling.inert);sibling.inert=true}
  branch=branch.parentElement
  if(branch===document.body)break
 }
}
function keydown(e){
 const top=layers.at(-1);if(!top||e.key!=='Tab')return
 const items=focusable(top),first=items[0]||top,last=items.at(-1)||top
 if(!top.contains(document.activeElement)||e.shiftKey&&document.activeElement===first||!e.shiftKey&&document.activeElement===last){e.preventDefault();(e.shiftKey?last:first).focus()}
 // Existing local traps must not advance focus a second time.
 e.stopImmediatePropagation()
}
function focusin(e){const top=layers.at(-1);if(top&&!top.contains(e.target))focus(top)}
function activate(el){
 const s=state.get(el);if(!s||layers.includes(el))return
 s.previous=document.activeElement
 if(!layers.length){overflow=document.body.style.overflow;document.body.style.overflow='hidden';document.addEventListener('keydown',keydown,true);document.addEventListener('focusin',focusin,true)}
 layers.push(el);el.setAttribute('tabindex','-1');el.setAttribute('role','dialog');el.setAttribute('aria-modal','true')
 s.overlay=el.parentElement;s.z=s.overlay.style.zIndex;s.overlay.style.zIndex=String(2100+layers.length*20)
 isolate();if(!el.contains(document.activeElement))focus(el)
}
function deactivate(el){
 const i=layers.indexOf(el);if(i<0)return
 const wasTop=i===layers.length-1,s=state.get(el);layers.splice(i,1);if(s?.overlay)s.overlay.style.zIndex=s.z
 isolate()
 if(!layers.length){document.body.style.overflow=overflow;document.removeEventListener('keydown',keydown,true);document.removeEventListener('focusin',focusin,true)}
 if(wasTop){const target=s?.previous;if(target?.isConnected&&!target.closest('[inert]'))target.focus({preventScroll:true});else if(layers.length)focus(layers.at(-1))}
}
export const dialogLayer={
 mounted(el,binding){state.set(el,{});if(binding.value!==false)activate(el)},
 updated(el,binding){if(binding.value===false)deactivate(el);else activate(el)},
 beforeUnmount(el){deactivate(el);state.delete(el)},
}
