import{c as C,e as P}from"./chunk-GCY6LNPH.js";var g={ATTRIBUTE:1,CHILD:2,PROPERTY:3,BOOLEAN_ATTRIBUTE:4,EVENT:5,ELEMENT:6},x=e=>(...i)=>({_$litDirective$:e,values:i}),m=class{constructor(i){}get _$AU(){return this._$AM._$AU}_$AT(i,s,c){this._$Ct=i,this._$AM=s,this._$Ci=c}_$AS(i,s){return this.update(i,s)}update(i,s){return this.render(...s)}};var{I:V}=P,E=e=>e;var M=()=>document.createComment(""),_=(e,i,s)=>{let c=e._$AA.parentNode,t=i===void 0?e._$AB:i._$AA;if(s===void 0){let o=c.insertBefore(M(),t),r=c.insertBefore(M(),t);s=new V(o,r,e,e.options)}else{let o=s._$AB.nextSibling,r=s._$AM,$=r!==e;if($){let a;s._$AQ?.(e),s._$AM=e,s._$AP!==void 0&&(a=e._$AU)!==r._$AU&&s._$AP(a)}if(o!==t||$){let a=s._$AA;for(;a!==o;){let A=E(a).nextSibling;E(c).insertBefore(a,t),a=A}}}return s},p=(e,i,s=e)=>(e._$AI(i,s),e),H={},R=(e,i=H)=>e._$AH=i,D=e=>e._$AH,v=e=>{e._$AR(),e._$AA.remove()};var B=(e,i,s)=>{let c=new Map;for(let t=i;t<=s;t++)c.set(e[t],t);return c},N=x(class extends m{constructor(e){if(super(e),e.type!==g.CHILD)throw Error("repeat() can only be used in text expressions")}dt(e,i,s){let c;s===void 0?s=i:i!==void 0&&(c=i);let t=[],o=[],r=0;for(let $ of e)t[r]=c?c($,r):r,o[r]=s($,r),r++;return{values:o,keys:t}}render(e,i,s){return this.dt(e,i,s).values}update(e,[i,s,c]){let t=D(e),{values:o,keys:r}=this.dt(i,s,c);if(!Array.isArray(t))return this.ut=r,o;let $=this.ut??=[],a=[],A,T,l=0,u=t.length-1,n=0,d=o.length-1;for(;l<=u&&n<=d;)if(t[l]===null)l++;else if(t[u]===null)u--;else if($[l]===r[n])a[n]=p(t[l],o[n]),l++,n++;else if($[u]===r[d])a[d]=p(t[u],o[d]),u--,d--;else if($[l]===r[d])a[d]=p(t[l],o[d]),_(e,a[d+1],t[l]),l++,d--;else if($[u]===r[n])a[n]=p(t[u],o[n]),_(e,t[l],t[u]),u--,n++;else if(A===void 0&&(A=B(r,n,d),T=B($,l,u)),A.has($[l]))if(A.has($[u])){let f=T.get(r[n]),h=f!==void 0?t[f]:null;if(h===null){let y=_(e,t[l]);p(y,o[n]),a[n]=y}else a[n]=p(h,o[n]),_(e,t[l],h),t[f]=null;n++}else v(t[u]),u--;else v(t[l]),l++;for(;n<=d;){let f=_(e,a[d+1]);p(f,o[n]),a[n++]=f}for(;l<=u;){let f=t[l++];f!==null&&v(f)}return this.ut=r,R(e,a),C}});export{N as a};
/*! Bundled license information:

lit-html/directive.js:
  (**
   * @license
   * Copyright 2017 Google LLC
   * SPDX-License-Identifier: BSD-3-Clause
   *)

lit-html/directive-helpers.js:
  (**
   * @license
   * Copyright 2020 Google LLC
   * SPDX-License-Identifier: BSD-3-Clause
   *)

lit-html/directives/repeat.js:
  (**
   * @license
   * Copyright 2017 Google LLC
   * SPDX-License-Identifier: BSD-3-Clause
   *)
*/
