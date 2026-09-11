var ce=globalThis,de=ce.ShadowRoot&&(ce.ShadyCSS===void 0||ce.ShadyCSS.nativeShadow)&&"adoptedStyleSheets"in Document.prototype&&"replace"in CSSStyleSheet.prototype,Ue=Symbol(),ut=new WeakMap,X=class{constructor(e,t,i){if(this._$cssResult$=!0,i!==Ue)throw Error("CSSResult is not constructable. Use `unsafeCSS` or `css` instead.");this.cssText=e,this.t=t}get styleSheet(){let e=this.o,t=this.t;if(de&&e===void 0){let i=t!==void 0&&t.length===1;i&&(e=ut.get(t)),e===void 0&&((this.o=e=new CSSStyleSheet).replaceSync(this.cssText),i&&ut.set(t,e))}return e}toString(){return this.cssText}},ht=s=>new X(typeof s=="string"?s:s+"",void 0,Ue),m=(s,...e)=>{let t=s.length===1?s[0]:e.reduce((i,a,o)=>i+(c=>{if(c._$cssResult$===!0)return c.cssText;if(typeof c=="number")return c;throw Error("Value passed to 'css' function must be a 'css' function result: "+c+". Use 'unsafeCSS' to pass non-literal values, but take care to ensure page security.")})(a)+s[o+1],s[0]);return new X(t,s,Ue)},pt=(s,e)=>{if(de)s.adoptedStyleSheets=e.map(t=>t instanceof CSSStyleSheet?t:t.styleSheet);else for(let t of e){let i=document.createElement("style"),a=ce.litNonce;a!==void 0&&i.setAttribute("nonce",a),i.textContent=t.cssText,s.appendChild(i)}},Te=de?s=>s:s=>s instanceof CSSStyleSheet?(e=>{let t="";for(let i of e.cssRules)t+=i.cssText;return ht(t)})(s):s;var{is:us,defineProperty:hs,getOwnPropertyDescriptor:ps,getOwnPropertyNames:ms,getOwnPropertySymbols:gs,getPrototypeOf:fs}=Object,ue=globalThis,mt=ue.trustedTypes,bs=mt?mt.emptyScript:"",ys=ue.reactiveElementPolyfillSupport,ee=(s,e)=>s,Oe={toAttribute(s,e){switch(e){case Boolean:s=s?bs:null;break;case Object:case Array:s=s==null?s:JSON.stringify(s)}return s},fromAttribute(s,e){let t=s;switch(e){case Boolean:t=s!==null;break;case Number:t=s===null?null:Number(s);break;case Object:case Array:try{t=JSON.parse(s)}catch{t=null}}return t}},ft=(s,e)=>!us(s,e),gt={attribute:!0,type:String,converter:Oe,reflect:!1,useDefault:!1,hasChanged:ft};Symbol.metadata??=Symbol("metadata"),ue.litPropertyMetadata??=new WeakMap;var N=class extends HTMLElement{static addInitializer(e){this._$Ei(),(this.l??=[]).push(e)}static get observedAttributes(){return this.finalize(),this._$Eh&&[...this._$Eh.keys()]}static createProperty(e,t=gt){if(t.state&&(t.attribute=!1),this._$Ei(),this.prototype.hasOwnProperty(e)&&((t=Object.create(t)).wrapped=!0),this.elementProperties.set(e,t),!t.noAccessor){let i=Symbol(),a=this.getPropertyDescriptor(e,i,t);a!==void 0&&hs(this.prototype,e,a)}}static getPropertyDescriptor(e,t,i){let{get:a,set:o}=ps(this.prototype,e)??{get(){return this[t]},set(c){this[t]=c}};return{get:a,set(c){let f=a?.call(this);o?.call(this,c),this.requestUpdate(e,f,i)},configurable:!0,enumerable:!0}}static getPropertyOptions(e){return this.elementProperties.get(e)??gt}static _$Ei(){if(this.hasOwnProperty(ee("elementProperties")))return;let e=fs(this);e.finalize(),e.l!==void 0&&(this.l=[...e.l]),this.elementProperties=new Map(e.elementProperties)}static finalize(){if(this.hasOwnProperty(ee("finalized")))return;if(this.finalized=!0,this._$Ei(),this.hasOwnProperty(ee("properties"))){let t=this.properties,i=[...ms(t),...gs(t)];for(let a of i)this.createProperty(a,t[a])}let e=this[Symbol.metadata];if(e!==null){let t=litPropertyMetadata.get(e);if(t!==void 0)for(let[i,a]of t)this.elementProperties.set(i,a)}this._$Eh=new Map;for(let[t,i]of this.elementProperties){let a=this._$Eu(t,i);a!==void 0&&this._$Eh.set(a,t)}this.elementStyles=this.finalizeStyles(this.styles)}static finalizeStyles(e){let t=[];if(Array.isArray(e)){let i=new Set(e.flat(1/0).reverse());for(let a of i)t.unshift(Te(a))}else e!==void 0&&t.push(Te(e));return t}static _$Eu(e,t){let i=t.attribute;return i===!1?void 0:typeof i=="string"?i:typeof e=="string"?e.toLowerCase():void 0}constructor(){super(),this._$Ep=void 0,this.isUpdatePending=!1,this.hasUpdated=!1,this._$Em=null,this._$Ev()}_$Ev(){this._$ES=new Promise(e=>this.enableUpdating=e),this._$AL=new Map,this._$E_(),this.requestUpdate(),this.constructor.l?.forEach(e=>e(this))}addController(e){(this._$EO??=new Set).add(e),this.renderRoot!==void 0&&this.isConnected&&e.hostConnected?.()}removeController(e){this._$EO?.delete(e)}_$E_(){let e=new Map,t=this.constructor.elementProperties;for(let i of t.keys())this.hasOwnProperty(i)&&(e.set(i,this[i]),delete this[i]);e.size>0&&(this._$Ep=e)}createRenderRoot(){let e=this.shadowRoot??this.attachShadow(this.constructor.shadowRootOptions);return pt(e,this.constructor.elementStyles),e}connectedCallback(){this.renderRoot??=this.createRenderRoot(),this.enableUpdating(!0),this._$EO?.forEach(e=>e.hostConnected?.())}enableUpdating(e){}disconnectedCallback(){this._$EO?.forEach(e=>e.hostDisconnected?.())}attributeChangedCallback(e,t,i){this._$AK(e,i)}_$ET(e,t){let i=this.constructor.elementProperties.get(e),a=this.constructor._$Eu(e,i);if(a!==void 0&&i.reflect===!0){let o=(i.converter?.toAttribute!==void 0?i.converter:Oe).toAttribute(t,i.type);this._$Em=e,o==null?this.removeAttribute(a):this.setAttribute(a,o),this._$Em=null}}_$AK(e,t){let i=this.constructor,a=i._$Eh.get(e);if(a!==void 0&&this._$Em!==a){let o=i.getPropertyOptions(a),c=typeof o.converter=="function"?{fromAttribute:o.converter}:o.converter?.fromAttribute!==void 0?o.converter:Oe;this._$Em=a;let f=c.fromAttribute(t,o.type);this[a]=f??this._$Ej?.get(a)??f,this._$Em=null}}requestUpdate(e,t,i,a=!1,o){if(e!==void 0){let c=this.constructor;if(a===!1&&(o=this[e]),i??=c.getPropertyOptions(e),!((i.hasChanged??ft)(o,t)||i.useDefault&&i.reflect&&o===this._$Ej?.get(e)&&!this.hasAttribute(c._$Eu(e,i))))return;this.C(e,t,i)}this.isUpdatePending===!1&&(this._$ES=this._$EP())}C(e,t,{useDefault:i,reflect:a,wrapped:o},c){i&&!(this._$Ej??=new Map).has(e)&&(this._$Ej.set(e,c??t??this[e]),o!==!0||c!==void 0)||(this._$AL.has(e)||(this.hasUpdated||i||(t=void 0),this._$AL.set(e,t)),a===!0&&this._$Em!==e&&(this._$Eq??=new Set).add(e))}async _$EP(){this.isUpdatePending=!0;try{await this._$ES}catch(t){Promise.reject(t)}let e=this.scheduleUpdate();return e!=null&&await e,!this.isUpdatePending}scheduleUpdate(){return this.performUpdate()}performUpdate(){if(!this.isUpdatePending)return;if(!this.hasUpdated){if(this.renderRoot??=this.createRenderRoot(),this._$Ep){for(let[a,o]of this._$Ep)this[a]=o;this._$Ep=void 0}let i=this.constructor.elementProperties;if(i.size>0)for(let[a,o]of i){let{wrapped:c}=o,f=this[a];c!==!0||this._$AL.has(a)||f===void 0||this.C(a,void 0,o,f)}}let e=!1,t=this._$AL;try{e=this.shouldUpdate(t),e?(this.willUpdate(t),this._$EO?.forEach(i=>i.hostUpdate?.()),this.update(t)):this._$EM()}catch(i){throw e=!1,this._$EM(),i}e&&this._$AE(t)}willUpdate(e){}_$AE(e){this._$EO?.forEach(t=>t.hostUpdated?.()),this.hasUpdated||(this.hasUpdated=!0,this.firstUpdated(e)),this.updated(e)}_$EM(){this._$AL=new Map,this.isUpdatePending=!1}get updateComplete(){return this.getUpdateComplete()}getUpdateComplete(){return this._$ES}shouldUpdate(e){return!0}update(e){this._$Eq&&=this._$Eq.forEach(t=>this._$ET(t,this[t])),this._$EM()}updated(e){}firstUpdated(e){}};N.elementStyles=[],N.shadowRootOptions={mode:"open"},N[ee("elementProperties")]=new Map,N[ee("finalized")]=new Map,ys?.({ReactiveElement:N}),(ue.reactiveElementVersions??=[]).push("2.1.2");var He=globalThis,bt=s=>s,he=He.trustedTypes,yt=he?he.createPolicy("lit-html",{createHTML:s=>s}):void 0,Me="$lit$",H=`lit$${Math.random().toFixed(9).slice(2)}$`,Ie="?"+H,vs=`<${Ie}>`,F=document,se=()=>F.createComment(""),ie=s=>s===null||typeof s!="object"&&typeof s!="function",Le=Array.isArray,kt=s=>Le(s)||typeof s?.[Symbol.iterator]=="function",Ne=`[ 	
\f\r]`,te=/<(?:(!--|\/[^a-zA-Z])|(\/?[a-zA-Z][^>\s]*)|(\/?$))/g,vt=/-->/g,_t=/>/g,q=RegExp(`>|${Ne}(?:([^\\s"'>=/]+)(${Ne}*=${Ne}*(?:[^ 	
\f\r"'\`<>=]|("|')|))|$)`,"g"),$t=/'/g,wt=/"/g,At=/^(?:script|style|textarea|title)$/i,De=s=>(e,...t)=>({_$litType$:s,strings:e,values:t}),n=De(1),Fs=De(2),Vs=De(3),M=Symbol.for("lit-noChange"),l=Symbol.for("lit-nothing"),St=new WeakMap,z=F.createTreeWalker(F,129);function Pt(s,e){if(!Le(s)||!s.hasOwnProperty("raw"))throw Error("invalid template strings array");return yt!==void 0?yt.createHTML(e):e}var xt=(s,e)=>{let t=s.length-1,i=[],a,o=e===2?"<svg>":e===3?"<math>":"",c=te;for(let f=0;f<t;f++){let d=s[f],y,$,p=-1,v=0;for(;v<d.length&&(c.lastIndex=v,$=c.exec(d),$!==null);)v=c.lastIndex,c===te?$[1]==="!--"?c=vt:$[1]!==void 0?c=_t:$[2]!==void 0?(At.test($[2])&&(a=RegExp("</"+$[2],"g")),c=q):$[3]!==void 0&&(c=q):c===q?$[0]===">"?(c=a??te,p=-1):$[1]===void 0?p=-2:(p=c.lastIndex-$[2].length,y=$[1],c=$[3]===void 0?q:$[3]==='"'?wt:$t):c===wt||c===$t?c=q:c===vt||c===_t?c=te:(c=q,a=void 0);let b=c===q&&s[f+1].startsWith("/>")?" ":"";o+=c===te?d+vs:p>=0?(i.push(y),d.slice(0,p)+Me+d.slice(p)+H+b):d+H+(p===-2?f:b)}return[Pt(s,o+(s[t]||"<?>")+(e===2?"</svg>":e===3?"</math>":"")),i]},re=class s{constructor({strings:e,_$litType$:t},i){let a;this.parts=[];let o=0,c=0,f=e.length-1,d=this.parts,[y,$]=xt(e,t);if(this.el=s.createElement(y,i),z.currentNode=this.el.content,t===2||t===3){let p=this.el.content.firstChild;p.replaceWith(...p.childNodes)}for(;(a=z.nextNode())!==null&&d.length<f;){if(a.nodeType===1){if(a.hasAttributes())for(let p of a.getAttributeNames())if(p.endsWith(Me)){let v=$[c++],b=a.getAttribute(p).split(H),w=/([.?@])?(.*)/.exec(v);d.push({type:1,index:o,name:w[2],strings:b,ctor:w[1]==="."?me:w[1]==="?"?ge:w[1]==="@"?fe:G}),a.removeAttribute(p)}else p.startsWith(H)&&(d.push({type:6,index:o}),a.removeAttribute(p));if(At.test(a.tagName)){let p=a.textContent.split(H),v=p.length-1;if(v>0){a.textContent=he?he.emptyScript:"";for(let b=0;b<v;b++)a.append(p[b],se()),z.nextNode(),d.push({type:2,index:++o});a.append(p[v],se())}}}else if(a.nodeType===8)if(a.data===Ie)d.push({type:2,index:o});else{let p=-1;for(;(p=a.data.indexOf(H,p+1))!==-1;)d.push({type:7,index:o}),p+=H.length-1}o++}}static createElement(e,t){let i=F.createElement("template");return i.innerHTML=e,i}};function V(s,e,t=s,i){if(e===M)return e;let a=i!==void 0?t._$Co?.[i]:t._$Cl,o=ie(e)?void 0:e._$litDirective$;return a?.constructor!==o&&(a?._$AO?.(!1),o===void 0?a=void 0:(a=new o(s),a._$AT(s,t,i)),i!==void 0?(t._$Co??=[])[i]=a:t._$Cl=a),a!==void 0&&(e=V(s,a._$AS(s,e.values),a,i)),e}var pe=class{constructor(e,t){this._$AV=[],this._$AN=void 0,this._$AD=e,this._$AM=t}get parentNode(){return this._$AM.parentNode}get _$AU(){return this._$AM._$AU}u(e){let{el:{content:t},parts:i}=this._$AD,a=(e?.creationScope??F).importNode(t,!0);z.currentNode=a;let o=z.nextNode(),c=0,f=0,d=i[0];for(;d!==void 0;){if(c===d.index){let y;d.type===2?y=new j(o,o.nextSibling,this,e):d.type===1?y=new d.ctor(o,d.name,d.strings,this,e):d.type===6&&(y=new be(o,this,e)),this._$AV.push(y),d=i[++f]}c!==d?.index&&(o=z.nextNode(),c++)}return z.currentNode=F,a}p(e){let t=0;for(let i of this._$AV)i!==void 0&&(i.strings!==void 0?(i._$AI(e,i,t),t+=i.strings.length-2):i._$AI(e[t])),t++}},j=class s{get _$AU(){return this._$AM?._$AU??this._$Cv}constructor(e,t,i,a){this.type=2,this._$AH=l,this._$AN=void 0,this._$AA=e,this._$AB=t,this._$AM=i,this.options=a,this._$Cv=a?.isConnected??!0}get parentNode(){let e=this._$AA.parentNode,t=this._$AM;return t!==void 0&&e?.nodeType===11&&(e=t.parentNode),e}get startNode(){return this._$AA}get endNode(){return this._$AB}_$AI(e,t=this){e=V(this,e,t),ie(e)?e===l||e==null||e===""?(this._$AH!==l&&this._$AR(),this._$AH=l):e!==this._$AH&&e!==M&&this._(e):e._$litType$!==void 0?this.$(e):e.nodeType!==void 0?this.T(e):kt(e)?this.k(e):this._(e)}O(e){return this._$AA.parentNode.insertBefore(e,this._$AB)}T(e){this._$AH!==e&&(this._$AR(),this._$AH=this.O(e))}_(e){this._$AH!==l&&ie(this._$AH)?this._$AA.nextSibling.data=e:this.T(F.createTextNode(e)),this._$AH=e}$(e){let{values:t,_$litType$:i}=e,a=typeof i=="number"?this._$AC(e):(i.el===void 0&&(i.el=re.createElement(Pt(i.h,i.h[0]),this.options)),i);if(this._$AH?._$AD===a)this._$AH.p(t);else{let o=new pe(a,this),c=o.u(this.options);o.p(t),this.T(c),this._$AH=o}}_$AC(e){let t=St.get(e.strings);return t===void 0&&St.set(e.strings,t=new re(e)),t}k(e){Le(this._$AH)||(this._$AH=[],this._$AR());let t=this._$AH,i,a=0;for(let o of e)a===t.length?t.push(i=new s(this.O(se()),this.O(se()),this,this.options)):i=t[a],i._$AI(o),a++;a<t.length&&(this._$AR(i&&i._$AB.nextSibling,a),t.length=a)}_$AR(e=this._$AA.nextSibling,t){for(this._$AP?.(!1,!0,t);e!==this._$AB;){let i=bt(e).nextSibling;bt(e).remove(),e=i}}setConnected(e){this._$AM===void 0&&(this._$Cv=e,this._$AP?.(e))}},G=class{get tagName(){return this.element.tagName}get _$AU(){return this._$AM._$AU}constructor(e,t,i,a,o){this.type=1,this._$AH=l,this._$AN=void 0,this.element=e,this.name=t,this._$AM=a,this.options=o,i.length>2||i[0]!==""||i[1]!==""?(this._$AH=Array(i.length-1).fill(new String),this.strings=i):this._$AH=l}_$AI(e,t=this,i,a){let o=this.strings,c=!1;if(o===void 0)e=V(this,e,t,0),c=!ie(e)||e!==this._$AH&&e!==M,c&&(this._$AH=e);else{let f=e,d,y;for(e=o[0],d=0;d<o.length-1;d++)y=V(this,f[i+d],t,d),y===M&&(y=this._$AH[d]),c||=!ie(y)||y!==this._$AH[d],y===l?e=l:e!==l&&(e+=(y??"")+o[d+1]),this._$AH[d]=y}c&&!a&&this.j(e)}j(e){e===l?this.element.removeAttribute(this.name):this.element.setAttribute(this.name,e??"")}},me=class extends G{constructor(){super(...arguments),this.type=3}j(e){this.element[this.name]=e===l?void 0:e}},ge=class extends G{constructor(){super(...arguments),this.type=4}j(e){this.element.toggleAttribute(this.name,!!e&&e!==l)}},fe=class extends G{constructor(e,t,i,a,o){super(e,t,i,a,o),this.type=5}_$AI(e,t=this){if((e=V(this,e,t,0)??l)===M)return;let i=this._$AH,a=e===l&&i!==l||e.capture!==i.capture||e.once!==i.once||e.passive!==i.passive,o=e!==l&&(i===l||a);a&&this.element.removeEventListener(this.name,this,i),o&&this.element.addEventListener(this.name,this,e),this._$AH=e}handleEvent(e){typeof this._$AH=="function"?this._$AH.call(this.options?.host??this.element,e):this._$AH.handleEvent(e)}},be=class{constructor(e,t,i){this.element=e,this.type=6,this._$AN=void 0,this._$AM=t,this.options=i}get _$AU(){return this._$AM._$AU}_$AI(e){V(this,e)}},Et={M:Me,P:H,A:Ie,C:1,L:xt,R:pe,D:kt,V,I:j,H:G,N:ge,U:fe,B:me,F:be},_s=He.litHtmlPolyfillSupport;_s?.(re,j),(He.litHtmlVersions??=[]).push("3.3.3");var Ct=(s,e,t)=>{let i=t?.renderBefore??e,a=i._$litPart$;if(a===void 0){let o=t?.renderBefore??null;i._$litPart$=a=new j(e.insertBefore(se(),o),o,void 0,t??{})}return a._$AI(s),a};var Be=globalThis,L=class extends N{constructor(){super(...arguments),this.renderOptions={host:this},this._$Do=void 0}createRenderRoot(){let e=super.createRenderRoot();return this.renderOptions.renderBefore??=e.firstChild,e}update(e){let t=this.render();this.hasUpdated||(this.renderOptions.isConnected=this.isConnected),super.update(e),this._$Do=Ct(t,this.renderRoot,this.renderOptions)}connectedCallback(){super.connectedCallback(),this._$Do?.setConnected(!0)}disconnectedCallback(){super.disconnectedCallback(),this._$Do?.setConnected(!1)}render(){return M}};L._$litElement$=!0,L.finalized=!0,Be.litElementHydrateSupport?.({LitElement:L});var $s=Be.litElementPolyfillSupport;$s?.({LitElement:L});(Be.litElementVersions??=[]).push("4.2.2");var W={form:"",heading:"",label:"",input:"",select:"",check:"",button:"",buttonPrimary:"",buttonSecondary:"",alertError:"",alertSuccess:"",alertInfo:"",link:"",muted:""},ae={bootstrap5:{name:"bootstrap5",detect:'link[rel~="stylesheet"][href*="bootstrap"]',classes:{...W,heading:"h4",label:"form-label",input:"form-control",select:"form-select",check:"form-check-input",button:"btn btn-outline-dark",buttonPrimary:"btn btn-primary",buttonSecondary:"btn btn-secondary",alertError:"alert alert-danger",alertSuccess:"alert alert-success",alertInfo:"alert alert-primary",muted:"text-secondary"}},pure:{name:"pure",detect:'link[rel~="stylesheet"][href*="pure"]',classes:{...W,form:"pure-form pure-form-stacked",input:"pure-input-1",select:"pure-input-1",button:"pure-button",buttonPrimary:"pure-button pure-button-primary",buttonSecondary:"pure-button"}},tailwind:{name:"tailwind",detect:'link[rel~="stylesheet"][href*="tailwind"]',classes:{...W,heading:"text-xl font-semibold",label:"block mb-1",input:"block w-full rounded border border-gray-300 px-3 py-2 text-base focus:border-blue-500",select:"block w-full rounded border border-gray-300 px-3 py-2 text-base focus:border-blue-500",check:"rounded border-gray-300",button:"inline-block rounded px-4 py-2 bg-gray-200 text-gray-900 hover:bg-gray-300 disabled:opacity-60",buttonPrimary:"inline-block rounded px-4 py-2 bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-60",buttonSecondary:"inline-block rounded px-4 py-2 bg-gray-700 text-white hover:bg-gray-800 disabled:opacity-60",alertError:"rounded border border-red-300 bg-red-50 px-4 py-3 text-red-800",alertSuccess:"rounded border border-green-300 bg-green-50 px-4 py-3 text-green-800",alertInfo:"rounded border border-sky-300 bg-sky-50 px-4 py-3 text-sky-800",link:"underline",muted:"text-gray-500"}},custom:{name:"custom",detect:null,classes:{...W}},none:{name:"none",detect:null,classes:{...W}}},ye="bootstrap5",ii=Object.keys(W);function Rt(s){return ae[s]||ae[ye]}function Ut(s,e){if(!e)return s;let t={...s.classes},i=[];for(let[a,o]of Object.entries(e))a in t?t[a]=String(o):i.push(a);return i.length&&typeof console<"u"&&console.warn("[shop-ui] unbekannte Rollen in shopui.classes:",i.join(", ")),{...s,classes:t}}var ve=null,_e=ae[ye],ws=typeof CSSStyleSheet<"u"&&"replaceSync"in CSSStyleSheet.prototype&&"adoptedStyleSheets"in Document.prototype;function Ss(){return document.readyState!=="loading"?Promise.resolve():new Promise(s=>document.addEventListener("DOMContentLoaded",s,{once:!0}))}function Tt(s){if(!s)return null;if(typeof s=="object")return s;try{return JSON.parse(s)}catch{return console.warn("[shop-ui] data-shop-ui-classes ist kein gueltiges JSON, wird ignoriert"),null}}function ks(s){if(!s||typeof location>"u")return{};let e=new URLSearchParams(location.search),t=e.get("shop-ui-theme")||"",i=e.get("shop-ui-stylesheet")||"",a={};if(t&&t in ae&&(a.theme=t),i){let o=new URL(i,document.baseURI);o.origin===location.origin?a.stylesheet=o.href:console.warn("[shop-ui] shop-ui-stylesheet ignoriert: nicht same-origin")}return a}function As(){let s=typeof window<"u"&&window.ShopUIConfig||{},e=document.querySelector("script[data-shop-ui-theme]"),t=ks(e&&e.dataset.shopUiAllowUrl==="true");return{theme:t.theme||s.theme||e&&e.dataset.shopUiTheme||ye,stylesheet:t.stylesheet||s.stylesheet||e&&e.dataset.shopUiStylesheet||"",classes:Tt(s.classes)||Tt(e&&e.dataset.shopUiClasses)||null}}function Ps(s,e){if(e)return new URL(e,document.baseURI).href;if(!s.detect)return null;let t=document.querySelector(s.detect);return t?t.href:null}function Ot(){return ve||(ve=Ss().then(()=>{let s=As(),e=Ut(Rt(s.theme),s.classes);_e=e;let t=Ps(e,s.stylesheet);return t?fetch(t,{credentials:"same-origin"}).then(i=>i.ok?i.text():"").then(i=>({preset:e,text:i})):{preset:e,text:""}}).then(({preset:s,text:e})=>{if(!e)return{preset:s,sheet:null,text:""};if(!ws)return{preset:s,sheet:null,text:e};let t=new CSSStyleSheet;return t.replaceSync(e),{preset:s,sheet:t,text:e}}).catch(()=>({preset:_e,sheet:null,text:""})),ve)}function Nt(s,e){let t=[],i=[];for(let a of e)a&&(a.sheet?s.adoptedStyleSheets.includes(a.sheet)||t.push(a.sheet):a.text&&i.push(a.text));t.length&&(s.adoptedStyleSheets=[...t,...s.adoptedStyleSheets]);for(let a of i.reverse()){let o=document.createElement("style");o.textContent=a,s.insertBefore(o,s.firstChild)}}function Ht(){return _e}var xs=/[A-Z]/g;function Mt(s){let e="shop-"+s.replace(xs,i=>"-"+i.toLowerCase()),t=_e.classes[s];return t?e+" "+t:e}var $e=m`
  .shop-label {
    display: block;
    margin-bottom: 0.25rem;
    font-weight: var(--shop-label-weight, 400);
  }

  .shop-input,
  .shop-select {
    display: block;
    width: 100%;
    padding: var(--shop-input-padding, 0.375rem 0.75rem);
    border: var(--shop-border-width, 1px) solid var(--shop-border-color, #ced4da);
    border-radius: var(--shop-radius, 0.375rem);
    font: inherit;
    color: inherit;
    background: var(--shop-input-bg, #fff);
  }

  .shop-input:focus-visible,
  .shop-select:focus-visible {
    outline: 2px solid var(--shop-accent, #0d6efd);
    outline-offset: 1px;
  }

  .shop-button,
  .shop-button-primary,
  .shop-button-secondary {
    display: inline-block;
    padding: var(--shop-button-padding, 0.375rem 0.75rem);
    border: var(--shop-border-width, 1px) solid transparent;
    border-radius: var(--shop-radius, 0.375rem);
    font: inherit;
    cursor: pointer;
    background: var(--shop-button-bg, #6c757d);
    color: var(--shop-button-color, #fff);
  }

  .shop-button-primary {
    background: var(--shop-accent, #0d6efd);
  }

  .shop-button:disabled,
  .shop-button-primary:disabled,
  .shop-button-secondary:disabled {
    opacity: 0.65;
    cursor: default;
  }

  .shop-alert-error,
  .shop-alert-success,
  .shop-alert-info {
    padding: 0.75rem 1rem;
    border: var(--shop-border-width, 1px) solid transparent;
    border-radius: var(--shop-radius, 0.375rem);
  }

  .shop-alert-error {
    color: var(--shop-error-color, #842029);
    background: var(--shop-error-bg, #f8d7da);
    border-color: var(--shop-error-border, #f5c2c7);
  }

  .shop-alert-info {
    color: var(--shop-info-color, #084298);
    background: var(--shop-info-bg, #cfe2ff);
    border-color: var(--shop-info-border, #b6d4fe);
  }

  .shop-alert-success {
    color: var(--shop-success-color, #0f5132);
    background: var(--shop-success-bg, #d1e7dd);
    border-color: var(--shop-success-border, #badbcc);
  }
`;var Es=Ot(),Cs=$e.styleSheet?{sheet:$e.styleSheet,text:""}:{sheet:null,text:$e.cssText},h=class extends L{static baseStyles=m`
    :host {
      display: block;
    }
    :host([hidden]) {
      display: none;
    }
    *,
    *::before,
    *::after {
      box-sizing: border-box;
    }

    /* Ersetzt das verschachtelte row/col-Muster der alten Shortcodes:
       gestapelte Felder mit Abstand, auf eine maximale Breite begrenzt.
       Bewusst frameworkfrei — CSS Grid braucht kein Framework. */
    .fields {
      display: grid;
      gap: var(--shop-field-gap, 1.5rem);
      max-width: var(--shop-form-width, 32rem);
    }
    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      align-items: center;
      margin-top: var(--shop-field-gap, 1.5rem);
    }
  `;#e=!1;async connectedCallback(){super.connectedCallback(),!this.#e&&(Nt(this.renderRoot,[Cs,await Es]),this.#e=!0,this.requestUpdate())}shouldUpdate(e){return this.#e&&super.shouldUpdate(e)}cls(e){return Mt(e)}get themeName(){return Ht().name}$(e){return this.renderRoot?this.renderRoot.getElementById(e):null}};var qe="/shop-api/",I=class extends Error{constructor(e,t){super(e),this.name="ApiError",this.code=e||"SHOP_API_ERROR",this.data=t||null}};async function It(s,e){let t;try{t=await fetch(qe,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json"},body:JSON.stringify({action:s,...e})})}catch(a){throw new I("SHOP_NETWORK_ERROR",{cause:String(a)})}let i=null;try{i=await t.json()}catch{throw new I("SHOP_API_ERROR",{status:t.status})}if(!t.ok||i&&i.success===!1)throw new I(i&&i.text?i.text:"SHOP_API_ERROR",i);return i?.payload??{}}var K=null;function U(){return K||(K=It("getContext",{}).catch(s=>{throw K=null,s})),K}function D(){return K=null,U()}function we(s,e){return u("shopLogin",{email:s,password:e})}function Lt(){return u("shopLogout",{})}async function u(s,e={},t=!0){try{return await It(s,e)}catch(i){if(!(i instanceof I&&i.code==="SHOP_CONTEXT_ERROR"&&t&&s!=="getContext"))throw i;K=null;try{await U()}catch{}return u(s,e,!1)}}var P="shop:auth-changed",R="shop:cart-changed",Rs="shop:error",ne="shop:account-loaded";function x(s,e={}){document.dispatchEvent(new CustomEvent(s,{detail:e,bubbles:!0,composed:!0}))}function S(s,e){return document.addEventListener(s,e),()=>document.removeEventListener(s,e)}var ze={"login.heading":"Anmelden","login.email":"E-Mail","login.password":"Passwort","login.submit":"Einloggen","login.pending":"Anmeldung l\xE4uft \u2026","login.register":"Noch kein Kundenkonto? Jetzt registrieren","login.missing":"Bitte geben Sie E-Mail-Adresse und Passwort ein.","register.heading":"Kundenkonto anlegen","register.accountType":"Kontotyp","register.private":"Privat","register.business":"Gewerblich","register.companyName":"Firmenname","register.salutation":"Anrede","register.name":"Name","register.contactName":"Kontaktname","register.street":"Strasse und Hausnummer","register.city":"Ort","register.postcode":"PLZ","register.country":"Land","register.phone":"Telefon","register.credentials":"Ihre Zugangsdaten","register.email":"E-Mail","register.password":"Passwort","register.passwordRepeat":"Passwort-Wiederholung","register.sameAddress":"Rechnungsadresse als Lieferadresse verwenden","register.deliveryAddress":"Lieferadresse","register.dataProtectionLabel":"Datenschutzbestimmungen","register.dataProtection":"akzeptieren","register.dataProtectionHint":"Wir nehmen den Schutz Ihrer Privatsph\xE4re sehr ernst.","register.submit":"Kundenkonto anlegen","register.pending":"Wird angelegt \u2026","register.yes":"Ja","register.no":"Nein","register.required":"Bitte f\xFCllen Sie alle Pflichtfelder aus. Es fehlt: ","register.emailInvalid":"Die E-Mail-Adresse ist ung\xFCltig.","register.passwordMismatch":"Die Passw\xF6rter stimmen nicht \xFCberein.","register.dataProtectionMissing":"Bitte best\xE4tigen Sie die Datenschutzbestimmungen.","register.accountExistsHint":"Zu dieser E-Mail gibt es bereits ein Kundenkonto. Bitte melden Sie sich an.","register.loginLink":"Zur Anmeldung","cart.loading":"Warenkorb wird geladen \u2026","cart.positions":"Positionen","cart.empty":"Ihr Warenkorb ist leer.","cart.continue":"Weiter einkaufen","cart.quantity":"Anzahl","cart.increase":"Menge erh\xF6hen","cart.decrease":"Menge verringern","cart.delete":"L\xF6schen","cart.price":"Preis","cart.perUnit":"St\xFCck","cart.shipping":"zzgl. Versandkosten","cart.netNote":"*) Preise ohne MwSt.","cart.netTotal":"Nettobetrag","cart.netSuffix":"ohne MwSt.","cart.total":"Gesamtbetrag","cart.totalSuffix":"inkl. MwSt.","cart.checkout":"Zur Kasse","cart.paypal":"Mit PayPal bezahlen","addToCart.quantity":"Anzahl","addToCart.unit":"St\xFCck","addToCart.submit":"In den Warenkorb","addToCart.pending":"Wird hinzugef\xFCgt \u2026","addToCart.added":"Das Produkt wurde in den Warenkorb gelegt.","addToCart.viewCart":"Warenkorb anzeigen","account.loading":"Daten werden geladen \u2026","account.anonymous":"F\xFCr diesen Bereich m\xFCssen Sie angemeldet sein.","account.login":"Zur Anmeldung","account.retry":"Erneut versuchen","account.greeting":"Hallo","account.nav.label":"Kundenkonto","account.nav.overview":"\xDCbersicht","account.nav.profile":"Pers\xF6nliches Profil","account.nav.address":"Adressen","account.nav.order":"Bestellungen","account.nav.payment":"Zahlungsarten","account.edit":"Bearbeiten","account.save":"\xC4nderung speichern","account.saving":"Wird gespeichert \u2026","account.saved":"Ihre Daten wurden erfolgreich aktualisiert!","account.cancel":"Abbrechen","overview.profile":"Profil","overview.changePassword":"Passwort \xE4ndern","overview.payment":"Standard-Zahlungsmethode","overview.paymentNone":"Keine Zahlungsart ausgew\xE4hlt.","overview.billingAddress":"Rechnungsadresse","overview.deliveryAddress":"Standard-Lieferadresse","overview.deliveryIsBilling":"Die Rechnungsadresse ist auch die Lieferadresse.","profile.personal":"Pers\xF6nliche Daten","profile.credentials":"Zugangsdaten","profile.password":"Passwort","profile.confirmHint":"Bitte geben Sie Ihr aktuelles Passwort ein, um die \xC4nderungen zu best\xE4tigen.","profile.currentPassword":"Aktuelles Passwort","profile.newPassword":"Neues Passwort","profile.repeatPassword":"Passwort-Wiederholung","profile.companyMissing":"Bitte geben Sie Ihren Firmennamen ein!","profile.nameMissing":"Bitte geben Sie Ihren Namen ein!","profile.emailMissing":"Bitte geben Sie Ihre E-Mail-Adresse ein!","profile.passwordMissing":"Bitte geben Sie Ihr Passwort ein!","profile.newPasswordMissing":"Bitte geben Sie Ihr neues Passwort ein!","profile.repeatMissing":"Bitte best\xE4tigen Sie Ihr neues Passwort!","profile.emailSaved":"Ihre E-Mail-Adresse wurde erfolgreich aktualisiert!","profile.passwordSaved":"Ihr Passwort wurde erfolgreich aktualisiert!","payment.heading":"Standard-Zahlungsart","payment.none":"Es sind keine Zahlungsarten hinterlegt.","payment.saved":"Ihre Zahlungsart wurde gespeichert.","orders.empty":"Es sind keine Bestellungen vorhanden.","orders.from":"Bestellung vom","orders.positions":"Positionen","orders.total":"Gesamtbetrag","orders.grossSuffix":"inkl. MwSt.","orders.download":"Rechnung herunterladen","orders.back":"Zur\xFCck zu den Bestellungen","orders.details":"Bestelldetails","orders.number":"Bestellnummer","orders.date":"Bestelldatum","orders.deliveryAddress":"Lieferadresse","orders.quantity":"Anzahl","orders.price":"Preis","orders.perUnit":"St\xFCck","orders.grossNote":"*) Alle Preise inkl. MwSt.","addresses.billing":"Rechnungsadresse","addresses.standardDelivery":"Standard-Lieferadresse","addresses.available":"Verf\xFCgbare Lieferadressen","addresses.none":"Keine Lieferadressen gespeichert.","addresses.add":"Adresse hinzuf\xFCgen","addresses.new":"Neue Lieferadresse","addresses.edit":"Lieferadresse bearbeiten","addresses.save":"Adresse speichern","addresses.remove":"L\xF6schen","addresses.makeDefault":"Standard","addresses.isDefault":"(Standard)","addresses.takeBilling":"Rechnungsadresse \xFCbernehmen","addresses.confirmRemove":"Wollen Sie die Adresse wirklich l\xF6schen?","addresses.usedHint":"Diese Adresse geh\xF6rt zu einer Bestellung und kann nicht gel\xF6scht werden.","addresses.saved":"Ihre Adresse wurde erfolgreich aktualisiert!","addresses.streetMissing":"Bitte geben Sie Ihre Stra\xDFe ein!","addresses.cityMissing":"Bitte geben Sie Ihre Stadt ein!","addresses.postcodeMissing":"Bitte geben Sie Ihre Postleitzahl ein!","addresses.countryMissing":"Bitte geben Sie Ihr Land ein!","addresses.nameMissing":"Bitte geben Sie Ihren Namen ein!","header.login":"Login","header.logout":"Logout","header.register":"Registrieren","header.account":"Pers\xF6nliche Daten","header.cart":"Warenkorb","header.cartEmpty":"Ihr Warenkorb ist leer.","search.label":"Suche","search.placeholder":"Suchbegriff eingeben \u2026","search.submit":"Suchen","search.more":"Weitere Ergebnisse anzeigen","search.none":"Keine Ergebnisse gefunden","search.enterTerm":"Bitte Suchbegriff eingeben \u2026","results.loading":"Ergebnisse werden geladen \u2026","results.found":"Gefundene Artikel","contact.loading":"Formular wird geladen \u2026","contact.companyName":"Firmenname","contact.salutation":"Anrede","contact.name":"Name","contact.email":"E-Mail","contact.phone":"Telefon","contact.issue":"Anliegen","contact.submit":"Absenden","contact.sending":"Wird gesendet \u2026","contact.subject":"Kontaktanfrage","contact.productRequest":"Anfrage zum Produkt","contact.required":"Bitte f\xFCllen Sie alle Formularfelder mit einem Stern (*) aus. Das Feld ","contact.missing":" fehlt.","contact.emailInvalid":"Bitte pr\xFCfen Sie die E-Mail-Adresse noch einmal.","contact.sendFailed":"Entschuldigung, die Nachricht konnte nicht versendet werden. Bitte versuchen Sie es sp\xE4ter noch einmal.","checkout.loading":"Kasse wird geladen \u2026","checkout.addresses":"Rechnungs- und Lieferadresse","checkout.billing":"Rechnungsadresse","checkout.delivery":"Lieferadresse","checkout.deliveryIsBilling":"Entspricht der Rechnungsadresse","checkout.saved":"Gespeicherte Lieferadressen","checkout.default":"Standardadresse","checkout.newAddress":"Neue Adresse","checkout.newAddressTitle":"Neue Lieferadresse","checkout.prefill":"Aus gespeicherter Lieferadresse \xFCbernehmen","checkout.cancel":"Abbrechen","checkout.cart":"Warenkorb","checkout.buy":"Jetzt kaufen","checkout.buying":"Bestellung wird abgeschlossen \u2026","checkout.buyNote":"Mit dem Klick auf \u201EJetzt kaufen\u201C geben Sie eine zahlungspflichtige Bestellung ab. Ihre Daten werden sicher und verschl\xFCsselt \xFCbertragen.","checkout.emptyCart":"Ihr Warenkorb ist leer. Es gibt nichts zu bestellen.","checkout.createAccount":"Kundenkonto anlegen (optional)","checkout.createAccountHint":"Legen Sie zuerst das Kundenkonto an. Danach schlie\xDFen Sie die Bestellung als angemeldeter Kunde ab.","invoice.loading":"Bestellung wird geladen \u2026","invoice.noLink":"Zu dieser Adresse geh\xF6rt keine Bestellung.","invoice.toOrders":"Zu Ihren Bestellungen","invoice.mailTo":"Sofern noch nicht erfolgt, erhalten Sie die Rechnung per E-Mail an","invoice.mailFailed":"Die Rechnung konnte nicht per E-Mail versendet werden. Bitte laden Sie sie hier herunter.","invoice.downloadHint":"Sie k\xF6nnen die Rechnung auch sofort herunterladen.","invoice.download":"Rechnung sofort herunterladen","invoice.pendingTitle":"Ihre Zahlung wird noch best\xE4tigt.","invoice.pendingHint":"PayPal hat Ihre Zahlung angenommen, sie ist aber noch unterwegs \u2014 je nach Zahlungsart kann das einige Tage dauern. Sie m\xFCssen nichts weiter tun; \xFCberweisen Sie den Betrag bitte nicht noch einmal.","invoice.transferHint":"Bitte \xFCberweisen Sie den Betrag an folgendes Bankkonto:","invoice.bank":"Bank","invoice.iban":"IBAN","invoice.bic":"BIC","invoice.purpose":"Verwendungszweck","invoice.owner":"Kontoinhaber","invoice.amount":"Gesamtbetrag","invoice.deliveryHint":"Die Lieferung erfolgt nach Zahlungseingang an die folgende Adresse:",ACCOUNT_NOT_FOUND:"Das Kundenkonto mit dieser E-Mail existiert nicht.",WRONG_PASSWORD:"Das Passwort ist falsch.",ACCOUNT_EXISTS:"Das Kundenkonto existiert bereits.",SHOP_CONTEXT_ERROR:"Ihre Sitzung ist abgelaufen. Bitte laden Sie die Seite neu.",SHOP_NETWORK_ERROR:"Die Verbindung zum Shop ist fehlgeschlagen. Bitte versuchen Sie es erneut.",SHOP_API_ERROR:"Entschuldigung, es ist ein Fehler aufgetreten!",SHOP_DATABASE_ERROR:"Entschuldigung, es ist ein Fehler aufgetreten!",CUSTOMER_NOT_FOUND:"Das Kundenkonto wurde nicht gefunden.",EMAIL_EXISTS:"Die E-Mail-Adresse ist bereits in Verwendung!",INVOICE_LINK_NOT_FOUND:"Zu diesem Link wurde keine Rechnung gefunden.",CART_NOT_FOUND:"Der Warenkorb wurde nicht gefunden.",CART_POS_NOT_FOUND:"Diese Warenkorbposition gibt es nicht mehr. Bitte laden Sie die Seite neu.",ADDRESS_NOT_FOUND:"Diese Adresse gibt es nicht. Bitte laden Sie die Seite neu."},Us={"login.heading":"Sign in","login.email":"Email","login.password":"Password","login.submit":"Sign in","login.pending":"Signing in \u2026","login.register":"No account yet? Register now","login.missing":"Please enter your email address and password.","register.heading":"Create an account","register.accountType":"Account type","register.private":"Private","register.business":"Business","register.companyName":"Company name","register.salutation":"Salutation","register.name":"Name","register.contactName":"Contact name","register.street":"Street and number","register.city":"City","register.postcode":"Postal code","register.country":"Country","register.phone":"Phone","register.credentials":"Your credentials","register.email":"Email","register.password":"Password","register.passwordRepeat":"Repeat password","register.sameAddress":"Use billing address as delivery address","register.deliveryAddress":"Delivery address","register.dataProtectionLabel":"Privacy policy","register.dataProtection":"accept","register.dataProtectionHint":"We take the protection of your privacy seriously.","register.submit":"Create account","register.pending":"Creating \u2026","register.yes":"Yes","register.no":"No","register.required":"Please fill in all required fields. Missing: ","register.emailInvalid":"The email address is invalid.","register.passwordMismatch":"The passwords do not match.","register.dataProtectionMissing":"Please accept the privacy policy.","register.accountExistsHint":"An account already exists for this email. Please sign in.","register.loginLink":"Go to sign in","cart.loading":"Loading cart \u2026","cart.positions":"Items","cart.empty":"Your cart is empty.","cart.continue":"Continue shopping","cart.quantity":"Quantity","cart.increase":"Increase quantity","cart.decrease":"Decrease quantity","cart.delete":"Remove","cart.price":"Price","cart.perUnit":"unit","cart.shipping":"plus shipping","cart.netNote":"*) Prices excluding VAT.","cart.netTotal":"Net amount","cart.netSuffix":"excl. VAT","cart.total":"Total","cart.totalSuffix":"incl. VAT","cart.checkout":"Checkout","cart.paypal":"Pay with PayPal","addToCart.quantity":"Quantity","addToCart.unit":"pcs","addToCart.submit":"Add to cart","addToCart.pending":"Adding \u2026","addToCart.added":"The product was added to your cart.","addToCart.viewCart":"View cart","account.loading":"Loading \u2026","account.anonymous":"You need to be signed in to view this section.","account.login":"Go to sign in","account.retry":"Try again","account.greeting":"Hello","account.nav.label":"Account","account.nav.overview":"Overview","account.nav.profile":"Personal profile","account.nav.address":"Addresses","account.nav.order":"Orders","account.nav.payment":"Payment methods","account.edit":"Edit","account.save":"Save changes","account.saving":"Saving \u2026","account.saved":"Your data was updated successfully.","account.cancel":"Cancel","overview.profile":"Profile","overview.changePassword":"Change password","overview.payment":"Default payment method","overview.paymentNone":"No payment method selected.","overview.billingAddress":"Billing address","overview.deliveryAddress":"Default delivery address","overview.deliveryIsBilling":"The billing address is also the delivery address.","profile.personal":"Personal details","profile.credentials":"Credentials","profile.password":"Password","profile.confirmHint":"Please enter your current password to confirm the change.","profile.currentPassword":"Current password","profile.newPassword":"New password","profile.repeatPassword":"Repeat password","profile.companyMissing":"Please enter your company name.","profile.nameMissing":"Please enter your name.","profile.emailMissing":"Please enter your email address.","profile.passwordMissing":"Please enter your password.","profile.newPasswordMissing":"Please enter your new password.","profile.repeatMissing":"Please confirm your new password.","profile.emailSaved":"Your email address was updated successfully.","profile.passwordSaved":"Your password was updated successfully.","payment.heading":"Default payment method","payment.none":"No payment methods are configured.","payment.saved":"Your payment method was saved.","orders.empty":"There are no orders yet.","orders.from":"Order from","orders.positions":"Items","orders.total":"Total","orders.grossSuffix":"incl. VAT","orders.download":"Download invoice","orders.back":"Back to orders","orders.details":"Order details","orders.number":"Order number","orders.date":"Order date","orders.deliveryAddress":"Delivery address","orders.quantity":"Quantity","orders.price":"Price","orders.perUnit":"unit","orders.grossNote":"*) All prices incl. VAT.","addresses.billing":"Billing address","addresses.standardDelivery":"Default delivery address","addresses.available":"Available delivery addresses","addresses.none":"No delivery addresses saved.","addresses.add":"Add address","addresses.new":"New delivery address","addresses.edit":"Edit delivery address","addresses.save":"Save address","addresses.remove":"Remove","addresses.makeDefault":"Make default","addresses.isDefault":"(default)","addresses.takeBilling":"Use billing address","addresses.confirmRemove":"Do you really want to remove this address?","addresses.usedHint":"This address belongs to an order and cannot be removed.","addresses.saved":"Your address was updated successfully.","addresses.streetMissing":"Please enter your street.","addresses.cityMissing":"Please enter your city.","addresses.postcodeMissing":"Please enter your postal code.","addresses.countryMissing":"Please enter your country.","addresses.nameMissing":"Please enter a name.","header.login":"Log in","header.logout":"Log out","header.register":"Sign up","header.account":"Personal data","header.cart":"Cart","header.cartEmpty":"Your cart is empty.","search.label":"Search","search.placeholder":"Enter a search term \u2026","search.submit":"Search","search.more":"Show more results","search.none":"No results found","search.enterTerm":"Please enter a search term \u2026","results.loading":"Loading results \u2026","results.found":"Items found","contact.loading":"Loading the form \u2026","contact.companyName":"Company name","contact.salutation":"Salutation","contact.name":"Name","contact.email":"Email","contact.phone":"Phone","contact.issue":"Your message","contact.submit":"Send","contact.sending":"Sending \u2026","contact.subject":"Contact request","contact.productRequest":"Enquiry about product","contact.required":"Please fill in every field marked with an asterisk (*). Missing: ","contact.missing":".","contact.emailInvalid":"Please check the email address once more.","contact.sendFailed":"Sorry, the message could not be sent. Please try again later.","checkout.loading":"Loading checkout \u2026","checkout.addresses":"Billing and delivery address","checkout.billing":"Billing address","checkout.delivery":"Delivery address","checkout.deliveryIsBilling":"Same as the billing address","checkout.saved":"Saved delivery addresses","checkout.default":"Default address","checkout.newAddress":"New address","checkout.newAddressTitle":"New delivery address","checkout.prefill":"Copy from a saved delivery address","checkout.cancel":"Cancel","checkout.cart":"Cart","checkout.buy":"Buy now","checkout.buying":"Completing your order \u2026","checkout.buyNote":"By clicking \u201CBuy now\u201D you place a binding order. Your data is transmitted securely and encrypted.","checkout.emptyCart":"Your cart is empty. There is nothing to order.","checkout.createAccount":"Create a customer account (optional)","checkout.createAccountHint":"Create the account first. You can then complete the order as a signed-in customer.","invoice.loading":"Loading your order \u2026","invoice.noLink":"This address does not refer to an order.","invoice.toOrders":"To your orders","invoice.mailTo":"Unless already received, the invoice will be sent by email to","invoice.mailFailed":"The invoice could not be sent by email. Please download it here.","invoice.downloadHint":"You can also download the invoice right away.","invoice.download":"Download invoice now","invoice.pendingTitle":"Your payment is still being confirmed.","invoice.pendingHint":"PayPal has accepted your payment, but it is still on its way \u2014 depending on the payment method this can take a few days. There is nothing further for you to do; please do not transfer the amount again.","invoice.transferHint":"Please transfer the amount to the following bank account:","invoice.bank":"Bank","invoice.iban":"IBAN","invoice.bic":"BIC","invoice.purpose":"Reference","invoice.owner":"Account holder","invoice.amount":"Total","invoice.deliveryHint":"Delivery will be made to the following address once payment is received:",ACCOUNT_NOT_FOUND:"No account exists for this email address.",WRONG_PASSWORD:"The password is incorrect.",ACCOUNT_EXISTS:"This account already exists.",SHOP_CONTEXT_ERROR:"Your session has expired. Please reload the page.",SHOP_NETWORK_ERROR:"Could not reach the shop. Please try again.",SHOP_API_ERROR:"Sorry, something went wrong!",SHOP_DATABASE_ERROR:"Sorry, something went wrong!",CUSTOMER_NOT_FOUND:"The account could not be found.",EMAIL_EXISTS:"This email address is already in use.",INVOICE_LINK_NOT_FOUND:"No invoice was found for this link.",CART_NOT_FOUND:"The cart could not be found.",CART_POS_NOT_FOUND:"This cart item no longer exists. Please reload the page.",ADDRESS_NOT_FOUND:"This address does not exist. Please reload the page."},Dt={de:ze,en:Us};function Ts(){let s=(document.documentElement.lang||"de").slice(0,2).toLowerCase();return Dt[s]?s:"de"}function r(s){let e=Dt[Ts()];return s in e?e[s]:s in ze?ze[s]:s}var Fe=class extends h{static properties={redirectUrl:{type:String,attribute:"redirect-url"},registerUrl:{type:String,attribute:"register-url"},heading:{type:String},_busy:{state:!0},_error:{state:!0}};static styles=[h.baseStyles,m`
      .shop-message {
        margin-top: 1rem;
        max-width: var(--shop-form-width, 32rem);
      }
      .shop-message:empty {
        display: none;
      }
      .register-link {
        margin-top: 1.5rem;
      }
    `];constructor(){super(),this.redirectUrl="/",this.registerUrl="",this.heading="",this._busy=!1,this._error=""}render(){return n`
      ${this.heading?n`<h2 class=${this.cls("heading")} part="heading">${this.heading}</h2>`:l}

      <form id="form" class=${this.cls("form")} part="form" @submit=${this.#e} novalidate>
        <div class="fields">
          <div class="field" part="field">
            <label class=${this.cls("label")} part="label" for="email">${r("login.email")}</label>
            <input
              class=${this.cls("input")}
              part="input"
              id="email"
              name="email"
              type="email"
              autocomplete="username"
              inputmode="email"
              required
            />
          </div>
          <div class="field" part="field">
            <label class=${this.cls("label")} part="label" for="password">
              ${r("login.password")}
            </label>
            <input
              class=${this.cls("input")}
              part="input"
              id="password"
              name="password"
              type="password"
              autocomplete="current-password"
              required
            />
          </div>
        </div>

        <div class="actions">
          <button
            class=${this.cls("buttonSecondary")}
            part="submit"
            type="submit"
            ?disabled=${this._busy}
          >
            ${this._busy?r("login.pending"):r("login.submit")}
          </button>
        </div>

        <div class="shop-message" role="alert" aria-live="polite">
          ${this._error?n`<div class=${this.cls("alertError")} part="error">${this._error}</div>`:l}
        </div>
      </form>

      ${this.registerUrl?n`<div class="register-link">
            <a href=${this.registerUrl} part="register-link">${r("login.register")}</a>
          </div>`:l}
    `}firstUpdated(){if(!this.hasAttribute("autofocus"))return;let e=this.$("email");e&&e.focus()}async#e(e){if(e.preventDefault(),this._busy)return;let t=this.$("email").value.trim(),i=this.$("password").value;if(!t||!i){this._error=r("login.missing");return}this._busy=!0,this._error="";try{await we(t,i),x(P,{account:!0}),window.location.href=this.redirectUrl||"/"}catch(a){this._error=r(a.code),this._busy=!1,this.$("password").value="",this.$("password").focus()}}};customElements.define("shop-login",Fe);var Bt={ATTRIBUTE:1,CHILD:2,PROPERTY:3,BOOLEAN_ATTRIBUTE:4,EVENT:5,ELEMENT:6},qt=s=>(...e)=>({_$litDirective$:s,values:e}),Se=class{constructor(e){}get _$AU(){return this._$AM._$AU}_$AT(e,t,i){this._$Ct=e,this._$AM=t,this._$Ci=i}_$AS(e,t){return this.update(e,t)}update(e,t){return this.render(...t)}};var{I:Os}=Et,zt=s=>s;var Ft=()=>document.createComment(""),Z=(s,e,t)=>{let i=s._$AA.parentNode,a=e===void 0?s._$AB:e._$AA;if(t===void 0){let o=i.insertBefore(Ft(),a),c=i.insertBefore(Ft(),a);t=new Os(o,c,s,s.options)}else{let o=t._$AB.nextSibling,c=t._$AM,f=c!==s;if(f){let d;t._$AQ?.(s),t._$AM=s,t._$AP!==void 0&&(d=s._$AU)!==c._$AU&&t._$AP(d)}if(o!==a||f){let d=t._$AA;for(;d!==o;){let y=zt(d).nextSibling;zt(i).insertBefore(d,a),d=y}}}return t},B=(s,e,t=s)=>(s._$AI(e,t),s),Ns={},Vt=(s,e=Ns)=>s._$AH=e,Gt=s=>s._$AH,ke=s=>{s._$AR(),s._$AA.remove()};var jt=(s,e,t)=>{let i=new Map;for(let a=e;a<=t;a++)i.set(s[a],a);return i},k=qt(class extends Se{constructor(s){if(super(s),s.type!==Bt.CHILD)throw Error("repeat() can only be used in text expressions")}dt(s,e,t){let i;t===void 0?t=e:e!==void 0&&(i=e);let a=[],o=[],c=0;for(let f of s)a[c]=i?i(f,c):c,o[c]=t(f,c),c++;return{values:o,keys:a}}render(s,e,t){return this.dt(s,e,t).values}update(s,[e,t,i]){let a=Gt(s),{values:o,keys:c}=this.dt(e,t,i);if(!Array.isArray(a))return this.ut=c,o;let f=this.ut??=[],d=[],y,$,p=0,v=a.length-1,b=0,w=o.length-1;for(;p<=v&&b<=w;)if(a[p]===null)p++;else if(a[v]===null)v--;else if(f[p]===c[b])d[b]=B(a[p],o[b]),p++,b++;else if(f[v]===c[w])d[w]=B(a[v],o[w]),v--,w--;else if(f[p]===c[w])d[w]=B(a[p],o[w]),Z(s,d[w+1],a[p]),p++,w--;else if(f[v]===c[b])d[b]=B(a[v],o[b]),Z(s,a[p],a[v]),v--,b++;else if(y===void 0&&(y=jt(c,b,w),$=jt(f,p,v)),y.has(f[p]))if(y.has(f[v])){let O=$.get(c[b]),Re=O!==void 0?a[O]:null;if(Re===null){let dt=Z(s,a[p]);B(dt,o[b]),d[b]=dt}else d[b]=B(Re,o[b]),Z(s,a[p],Re),a[O]=null;b++}else ke(a[v]),v--;else ke(a[p]),p++;for(;b<=w;){let O=Z(s,d[w+1]);B(O,o[b]),d[b++]=O}for(;p<=v;){let O=a[p++];O!==null&&ke(O)}return this.ut=c,Vt(s,d),M}});var Hs=/\S+@\S+\.\S+/,Ve=class extends h{static properties={mode:{type:String},redirectUrl:{type:String,attribute:"redirect-url"},loginUrl:{type:String,attribute:"login-url"},dataProtectionUrl:{type:String,attribute:"data-protection-url"},heading:{type:String},_salutations:{state:!0},_loading:{state:!0},_busy:{state:!0},_error:{state:!0},_accountExists:{state:!0},_business:{state:!0},_otherDelivery:{state:!0},_dataProtection:{state:!0}};static styles=[h.baseStyles,m`
      .block {
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid var(--shop-border-color, #dee2e6);
      }
      .block-title {
        font-weight: 600;
        margin-bottom: 1rem;
      }
      fieldset {
        border: 0;
        margin: 0;
        padding: 0;
      }
      legend {
        padding: 0;
        margin-bottom: 0.5rem;
      }
      .choice {
        display: flex;
        gap: 1.5rem;
        align-items: center;
      }
      .choice label {
        display: flex;
        gap: 0.4rem;
        align-items: center;
        margin: 0;
      }
      .shop-message {
        margin-top: 1.5rem;
        max-width: var(--shop-form-width, 32rem);
      }
      .hint {
        margin-top: 0.5rem;
      }
    `];constructor(){super(),this.mode="account",this.redirectUrl="/registriert/",this.loginUrl="/login/",this.dataProtectionUrl="/datenschutz/",this.heading="",this._salutations=[],this._loading=!0,this._busy=!1,this._error="",this._accountExists=!1,this._business=!1,this._otherDelivery=!1,this._dataProtection=!0}get isGuest(){return this.mode==="guest"}#e=!1;async connectedCallback(){if(await super.connectedCallback(),!this.#e){this.#e=!0;try{let e=await u("getAccountSelections",{lang:document.documentElement.lang});this._salutations=e&&e.salutations||[]}catch(e){this._error=r(e.code)}finally{this._loading=!1}}}get#s(){return[{id:"company-name",label:r("register.companyName"),autocomplete:"organization",required:!0,visible:this._business},{id:"name",label:this._business?r("register.contactName"):r("register.name"),autocomplete:"name",required:!0},{id:"street",label:r("register.street"),autocomplete:"billing street-address",required:!0},{id:"city",label:r("register.city"),autocomplete:"billing address-level2",required:!0},{id:"postcode",label:r("register.postcode"),autocomplete:"billing postal-code",required:!0},{id:"country",label:r("register.country"),autocomplete:"billing country-name",required:!0},{id:"phone",label:r("register.phone"),autocomplete:"tel",type:"tel"}]}get#t(){return[{id:"shipping-name",label:r("register.name"),autocomplete:"shipping name",required:!0},{id:"shipping-street",label:r("register.street"),autocomplete:"shipping street-address",required:!0},{id:"shipping-city",label:r("register.city"),autocomplete:"shipping address-level2",required:!0},{id:"shipping-postcode",label:r("register.postcode"),autocomplete:"shipping postal-code",required:!0},{id:"shipping-country",label:r("register.country"),autocomplete:"shipping country-name",required:!0},{id:"shipping-phone",label:r("register.phone"),autocomplete:"shipping tel",type:"tel"},{id:"shipping-email",label:r("register.email"),autocomplete:"shipping email",type:"email",visible:this.isGuest}]}get#r(){let e=[{id:"email",label:r("register.email"),autocomplete:"email",type:"email",required:!0}];return this.isGuest||e.push({id:"password",label:r("register.password"),autocomplete:"new-password",type:"password",required:!0},{id:"confirm-password",label:r("register.passwordRepeat"),autocomplete:"new-password",type:"password",required:!0}),e}#a(e){return n`
      <div class="field" part="field">
        <label class=${this.cls("label")} part="label" for=${e.id}>
          ${e.label}${e.required?"*":""}
        </label>
        <input
          class=${this.cls("input")}
          part="input"
          id=${e.id}
          name=${e.id}
          type=${e.type||"text"}
          autocomplete=${e.autocomplete||"off"}
          ?required=${!!e.required}
        />
      </div>
    `}#i(e){let t=e.filter(i=>i.visible!==!1);return n`<div class="fields">
      ${k(t,i=>i.id,i=>this.#a(i))}
    </div>`}render(){return this._loading?n`<p class=${this.cls("muted")}>…</p>`:n`
      ${this.heading?n`<h2 class=${this.cls("heading")} part="heading">${this.heading}</h2>`:l}

      <form id="form" class=${this.cls("form")} part="form" @submit=${this.#n} novalidate>
        <div class="fields">
          <div class="field" part="field">
            <label class=${this.cls("label")} part="label" for="account-type">
              ${r("register.accountType")}*
            </label>
            <select
              class=${this.cls("select")}
              part="select"
              id="account-type"
              name="account-type"
              @change=${e=>this._business=e.target.value==="false"}
            >
              <option value="true">${r("register.private")}</option>
              <option value="false">${r("register.business")}</option>
            </select>
          </div>

          <div class="field" part="field">
            <label class=${this.cls("label")} part="label" for="salutation">
              ${r("register.salutation")}
            </label>
            <select
              class=${this.cls("select")}
              part="select"
              id="salutation"
              name="salutation"
              autocomplete="honorific-prefix"
            >
              <option value=""></option>
              ${this._salutations.map(e=>n`<option value=${e.translation}>${e.translation}</option>`)}
            </select>
          </div>
        </div>

        ${this.#i(this.#s)}

        <div class="block">
          <fieldset>
            <legend>${r("register.sameAddress")}</legend>
            <div class="choice">
              <label>
                <input
                  class=${this.cls("check")}
                  type="radio"
                  name="same-address"
                  .checked=${!this._otherDelivery}
                  @change=${()=>this._otherDelivery=!1}
                />
                ${r("register.yes")}
              </label>
              <label>
                <input
                  class=${this.cls("check")}
                  type="radio"
                  name="same-address"
                  .checked=${this._otherDelivery}
                  @change=${()=>this._otherDelivery=!0}
                />
                ${r("register.no")}
              </label>
            </div>
          </fieldset>

          ${this._otherDelivery?n`
                <div class="block-title" style="margin-top:1.5rem">
                  ${r("register.deliveryAddress")}
                </div>
                ${this.#i(this.#t)}
              `:l}
        </div>

        <div class="block">
          <fieldset>
            <legend>
              <a href=${this.dataProtectionUrl} target="_blank" rel="noopener"
                >${r("register.dataProtectionLabel")}</a
              >
              ${r("register.dataProtection")}
            </legend>
            <div class="choice">
              <label>
                <input
                  class=${this.cls("check")}
                  type="radio"
                  name="data-protection"
                  .checked=${this._dataProtection}
                  @change=${()=>this._dataProtection=!0}
                />
                ${r("register.yes")}
              </label>
              <label>
                <input
                  class=${this.cls("check")}
                  type="radio"
                  name="data-protection"
                  .checked=${!this._dataProtection}
                  @change=${()=>this._dataProtection=!1}
                />
                ${r("register.no")}
              </label>
            </div>
            ${this._dataProtection?l:n`<div class="hint ${this.cls("muted")}">
                  ${r("register.dataProtectionHint")}
                </div>`}
          </fieldset>
        </div>

        <div class="block">
          ${this.isGuest?l:n`<div class="block-title">${r("register.credentials")}</div>`}
          ${this.#i(this.#r)}
        </div>

        ${this.isGuest?l:n`<div class="actions">
              <button
                class=${this.cls("buttonSecondary")}
                part="submit"
                type="submit"
                ?disabled=${this._busy}
              >
                ${this._busy?r("register.pending"):r("register.submit")}
              </button>
            </div>`}

        <div class="shop-message" role="alert" aria-live="polite">
          ${this._error?n`<div class=${this.cls("alertError")} part="error">
                ${this._error}
                ${this._accountExists?n` <a href=${this.loginUrl} part="login-link">${r("register.loginLink")}</a>`:l}
              </div>`:l}
        </div>
      </form>
    `}validate(){this._error="",this._accountExists=!1;let e=[this.#s,this.#r];this._otherDelivery&&e.push(this.#t);for(let t of e)for(let i of t){if(!i.required||i.visible===!1)continue;let a=this.$(i.id);if(a&&!a.value.trim())return this._error=r("register.required")+i.label,a.focus(),!1}return this._dataProtection?this.$("email")?Hs.test(this.$("email").value.trim())?!this.isGuest&&this.$("password").value!==this.$("confirm-password").value?(this._error=r("register.passwordMismatch"),this.$("confirm-password").focus(),!1):!0:(this._error=r("register.emailInvalid"),this.$("email").focus(),!1):!1:(this._error=r("register.dataProtectionMissing"),!1)}values(){let e=i=>{let a=this.$(i);return a?a.value.trim():""},t={"account-type":this._business?"false":"true","company-name":this._business?e("company-name"):"",salutation:e("salutation"),name:e("name"),street:e("street"),city:e("city"),postcode:e("postcode"),country:e("country"),phone:e("phone"),email:e("email"),password:this.isGuest?"":this.$("password").value,"add-delivery-address":this._otherDelivery?"true":"false"};for(let i of this.#t)t[i.id]=this._otherDelivery?e(i.id):"";return t}async register(){return u("registerAccount",{...this.values(),guest:this.isGuest})}async#n(e){if(e.preventDefault(),this._busy||this.isGuest||!this.validate())return;this._busy=!0;let t=this.$("email").value.trim(),i=this.$("password").value;try{await this.register(),await we(t,i),x(P,{account:!0}),window.location.href=this.redirectUrl||"/"}catch(a){this._busy=!1,a instanceof I&&a.code==="ACCOUNT_EXISTS"?(this._error=r("register.accountExistsHint"),this._accountExists=!0):this._error=r(a.code)}}};customElements.define("shop-register",Ve);var Wt={de:"de-DE",en:"en-GB"};function T(s){if(s==null||s==="")return 0;if(typeof s=="number")return Number.isFinite(s)?s:0;let e=String(s).trim(),t=e.includes(",")?e.replace(/\./g,"").replace(",","."):e,i=parseFloat(t);return Number.isFinite(i)?i:0}var Ge=null;function E(s){if(!Ge){let e=(document.documentElement.lang||"de").slice(0,2).toLowerCase();Ge=new Intl.NumberFormat(Wt[e]||Wt.de,{minimumFractionDigits:2,maximumFractionDigits:2})}return Ge.format(T(s))}function Ae(s){let e=!!s.incShippingCosts;return{incShipping:e,shipping:e?T(s.shippingCosts):0,netto:T(e?s.nettoTotalSumIncShipping:s.nettoTotalSum),total:T(e?s.totalSumIncShipping:s.totalSum)}}function Ms(s){return{id:s.id,referencedId:s.referencedId,label:s.label,quantity:Number(s.quantity)||1,unitPrice:T(s.unitPrice),totalPrice:T(s.totalPrice),thumbnail:s.thumbnail||null}}function Kt(s){return{currency:s.currency||"",positions:(s.positions||[]).map(Ms),totals:Ae(s)}}var je=class extends h{static properties={billingPage:{type:String,attribute:"billing-page"},canceledPage:{type:String,attribute:"canceled-page"},invoicingCanceled:{type:String,attribute:"invoicing-canceled"},mode:{type:String},checkoutUrl:{type:String,attribute:"checkout-url"},continueUrl:{type:String,attribute:"continue-url"},productUrl:{type:String,attribute:"product-url"},paypalImage:{type:String,attribute:"paypal-image"},heading:{type:String},_cart:{state:!0},_loading:{state:!0},_error:{state:!0},_message:{state:!0},_busy:{state:!0}};static styles=[h.baseStyles,m`
      .count {
        font-size: 1.25rem;
        margin: 0 0 1.5rem;
      }
      ul.positions {
        list-style: none;
        margin: 0;
        padding: 0;
      }
      .position {
        display: grid;
        grid-template-columns: minmax(0, 12rem) minmax(0, 1fr);
        gap: 1.5rem;
        align-items: start;
        padding: 1.25rem;
        margin-bottom: 0.75rem;
        border: 1px solid var(--shop-border-color, #dee2e6);
        border-radius: var(--shop-radius, 0.375rem);
        cursor: pointer;
      }
      /* Einspaltig, sobald zwei Spalten nicht mehr sinnvoll sind. Container
         Queries waeren richtiger, sind aber an ein contain-Setup gebunden,
         das der Host von aussen kaputtmachen kann. */
      @media (max-width: 40rem) {
        .position {
          grid-template-columns: minmax(0, 1fr);
        }
      }
      .thumb {
        display: block;
        width: 100%;
        height: auto;
      }
      .thumb-empty {
        aspect-ratio: 4 / 3;
        background: var(--shop-input-bg, #f8f9fa);
        border-radius: var(--shop-radius, 0.375rem);
      }
      .title {
        font-size: 1.1rem;
        font-weight: 600;
        margin: 0 0 1rem;
      }
      /* Als Button ausgezeichnet, weil das Ziel erst per API aufgeloest wird
         (getProductLink) — sieht aus wie ein Link, ist aber keiner. */
      .title button {
        font: inherit;
        color: inherit;
        background: none;
        border: 0;
        padding: 0;
        text-align: left;
        cursor: pointer;
        text-decoration: underline;
        text-underline-offset: 0.2em;
      }
      .line {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem 1rem;
        align-items: center;
        margin-bottom: 0.75rem;
      }
      .line:last-child {
        margin-bottom: 0;
      }
      .line-label {
        font-weight: 600;
      }
      .stepper {
        display: flex;
        align-items: stretch;
      }
      .stepper input {
        width: 5rem;
        text-align: center;
      }
      .stepper button {
        width: 2.5rem;
        cursor: pointer;
      }
      .unit-price {
        font-weight: 300;
      }
      .totals {
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid var(--shop-border-color, #dee2e6);
      }
      .totals p {
        margin: 0 0 0.5rem;
      }
      .grand {
        font-size: 1.25rem;
        font-weight: 600;
      }
      .suffix {
        font-size: 0.875rem;
        font-weight: 400;
        margin-left: 0.5rem;
      }
      .note {
        font-weight: 300;
        margin: 1rem 0;
      }
      .paypal img {
        display: block;
        height: 2.5rem;
        width: auto;
      }
      .position[aria-busy='true'] {
        opacity: 0.6;
      }
    `];#e=new Map;constructor(){super(),this.billingPage="",this.canceledPage="",this.invoicingCanceled="false",this.mode="cart",this.checkoutUrl="/kasse/#focus",this.continueUrl="/",this.productUrl="/produkt/",this.paypalImage="/images/paypal-de.png",this.heading="",this._cart=null,this._loading=!0,this._error="",this._message="",this._busy=new Set}connectedCallback(){super.connectedCallback(),this.#s()}disconnectedCallback(){super.disconnectedCallback();for(let e of this.#e.values())clearTimeout(e);this.#e.clear()}async#s(){this._loading=!0,this._error="";try{let e=this.#t?await u("checkout"):await u("getCart",{invoicingCanceled:this.invoicingCanceled==="true"});this._cart=Kt(e),this.#r()}catch(e){this._error=r(e.code||"SHOP_API_ERROR")}finally{this._loading=!1}}get#t(){return this.mode==="checkout"}#r(){let e=this._cart?this._cart.positions.length:0;x(R,{count:e,cart:this._cart})}#a(e,t){let i=new Set(this._busy);t?i.add(e):i.delete(e),this._busy=i}#i(e,t){let i=Math.max(1,Math.min(100,Math.round(Number(t)||1)));return i===e.quantity||(this._cart={...this._cart,positions:this._cart.positions.map(a=>a.id===e.id?{...a,quantity:i}:a)},this.#n(e.id,i)),i}#n(e,t){clearTimeout(this.#e.get(e)),this.#e.set(e,setTimeout(()=>{this.#e.delete(e),this.#l(e,t)},350))}async#l(e,t){this.#a(e,!0);try{let i=await u("changeQuantity",{pos:e,quantity:t});this._cart={...this._cart,positions:this._cart.positions.map(a=>a.id===e?{...a,unitPrice:T(i.unitPrice),totalPrice:T(i.totalPrice)}:a),totals:Ae(i)},this._message=""}catch(i){this._message=r(i.code||"SHOP_API_ERROR"),await this.#s()}finally{this.#a(e,!1)}}async#o(e){clearTimeout(this.#e.get(e.id)),this.#e.delete(e.id),this.#a(e.id,!0);try{let t=await u("deleteCartPos",{pos:e.id});this._cart={...this._cart,positions:this._cart.positions.filter(i=>i.id!==e.id),totals:Ae(t)},this._message="",this.#r()}catch(t){this._message=r(t.code||"SHOP_API_ERROR")}finally{this.#a(e.id,!1)}}async#d(e){try{let t=await u("getProductLink",{product:e.referencedId});t&&t.hyperlink&&(window.location.href=this.productUrl+t.hyperlink)}catch(t){this._message=r(t.code||"SHOP_API_ERROR")}}#u(e,t){t.composedPath().some(a=>a.tagName&&/^(INPUT|BUTTON|A|SELECT|LABEL)$/.test(a.tagName))||this.#d(e)}render(){if(this._loading)return n`<p class=${this.cls("muted")} role="status">${r("cart.loading")}</p>`;if(this._error)return n`<div class=${this.cls("alertError")} role="alert">${this._error}</div>`;let e=this._cart?this._cart.positions:[];return e.length?n`
      ${this.heading?n`<h2 class=${this.cls("heading")}>${this.heading}</h2>`:l}
      ${this._message?n`<div class=${this.cls("alertError")} role="alert">${this._message}</div>`:l}
      <p class="count ${this.cls("muted")}">${r("cart.positions")}: ${e.length}</p>
      <ul class="positions">
        ${k(e,t=>t.id,t=>this.#p(t))}
      </ul>
      ${this.#c()} ${this.#g()}
    `:this.#h()}#h(){return n`
      <p>${r("cart.empty")}</p>
      <p><a class=${this.cls("link")} href=${this.continueUrl}>${r("cart.continue")}</a></p>
    `}#p(e){let t=this._busy.has(e.id),i=this._cart.currency;return n`
      <li
        class="position"
        aria-busy=${t?"true":"false"}
        @click=${a=>this.#u(e,a)}
      >
        <div>
          ${e.thumbnail?n`<img class="thumb" src="/images/thumbnails/${e.thumbnail}" alt="" loading="lazy">`:n`<div class="thumb thumb-empty"></div>`}
        </div>
        <div>
          <h3 class="title">
            <button type="button" @click=${()=>this.#d(e)}>${e.label}</button>
          </h3>

          <div class="line">
            <span class="line-label">${r("cart.quantity")}:</span>
            <span class="stepper">
              <button
                type="button"
                class=${this.cls("button")}
                aria-label=${r("cart.decrease")}
                ?disabled=${t}
                @click=${()=>this.#m(e,-1)}
              >−</button>
              <input
                type="number"
                min="1"
                max="100"
                step="1"
                class=${this.cls("input")}
                aria-label=${r("cart.quantity")}
                .value=${String(e.quantity)}
                ?disabled=${t}
                @change=${a=>{a.target.value=String(this.#i(e,a.target.value))}}
              >
              <button
                type="button"
                class=${this.cls("button")}
                aria-label=${r("cart.increase")}
                ?disabled=${t}
                @click=${()=>this.#m(e,1)}
              >+</button>
            </span>
            <button
              type="button"
              class=${this.cls("buttonSecondary")}
              ?disabled=${t}
              @click=${()=>this.#o(e)}
            >${r("cart.delete")}</button>
          </div>

          <div class="line">
            <span class="line-label">${r("cart.price")}:</span>
            <span>${E(e.totalPrice)} ${i}*</span>
            <span class="unit-price ${this.cls("muted")}"
              >(${E(e.unitPrice)} ${i}* / ${r("cart.perUnit")})</span
            >
          </div>
        </div>
      </li>
    `}#m(e,t){if(t<0&&e.quantity<=1){this.#o(e);return}this.#i(e,e.quantity+t)}#c(){let{shipping:e,netto:t,total:i}=this._cart.totals,a=this._cart.currency;return n`
      <div class="totals">
        <p class="line-label">
          ${r("cart.shipping")}: ${E(e)} ${a}*
        </p>
        <p class="note ${this.cls("muted")}">${r("cart.netNote")}</p>
        <p>
          ${r("cart.netTotal")}: ${E(t)} ${a}
          <span class="suffix">${r("cart.netSuffix")}</span>
        </p>
        <p class="grand">
          ${r("cart.total")}: ${E(i)} ${a}
          <span class="suffix">${r("cart.totalSuffix")}</span>
        </p>
      </div>
    `}#g(){return this.#t?l:n`
      <div class="actions">
        <a class=${this.cls("buttonPrimary")} href=${this.checkoutUrl}>${r("cart.checkout")}</a>
        <a class="paypal" href=${this.#f()} aria-label=${r("cart.paypal")}>
          <img src=${this.paypalImage} alt=${r("cart.paypal")}>
        </a>
      </div>
    `}#f(){return`/shop-api/?${new URLSearchParams({action:"beginPayment",bill:this.billingPage,canceled:this.canceledPage})}`}};customElements.define("shop-cart",je);function xe(){try{let s=localStorage.getItem("cookiesConsent");return s==="accepted"||s==="declined"}catch{return!1}}var Pe=new Map;function Yt(s){if(!Pe.has(s)){let e=u("gtmGetProductInfo",{product_id:s}).then(t=>t&&t.product?t.product:null).catch(()=>(Pe.delete(s),null));Pe.set(s,e)}return Pe.get(s)}function Y(s){window.dataLayer=window.dataLayer||[],window.dataLayer.push(s)}function Qt(s,e){return{currency:s.currency||"EUR",value:s.price||0,items:[{item_name:s.name||"",item_id:s.id||"",price:s.price||0,item_category:s.category||"",quantity:e}]}}async function Jt(s){if(!xe())return;let e=await Yt(s);e&&Y({event:"view_item",ecommerce:Qt(e,1)})}async function Xt(s,e){if(!xe())return;let t=await Yt(s);t&&Y({event:"add_to_cart",ecommerce:Qt(t,e||1)})}function es(s){xe()&&(!s||!s.positions.length||Y({event:"begin_checkout",ecommerce:{currency:s.currency||"EUR",value:s.totals.total||0,items:s.positions.map(e=>({item_name:e.label||"",item_id:e.referencedId||"",price:e.totalPrice||0,quantity:e.quantity||1}))}}))}async function Zt(s){if(!globalThis.crypto||!globalThis.crypto.subtle)return null;try{let e=new TextEncoder().encode(String(s).trim().toLowerCase()),t=await globalThis.crypto.subtle.digest("SHA-256",e);return Array.from(new Uint8Array(t)).map(i=>i.toString(16).padStart(2,"0")).join("")}catch{return null}}async function ts(s){if(!xe()||!s)return;if(s.email){let t=await Zt(s.email);t&&Y({event:"emailAvailable",email:t})}if(s.phone){let t=await Zt(s.phone);t&&Y({event:"phoneAvailable",phone:t})}let e;try{e=await u("gtmGetPurchasedProducts",{ar_link:s.ar_link})}catch{return}!e||!e.purchased||Y({event:"purchase",ecommerce:{transaction_id:e.purchased.transaction_id||"",currency:e.purchased.currency||"EUR",value:e.purchased.value||0,shipping:e.purchased.shipping||0,items:(e.products||[]).map(t=>({item_name:t.name||"",item_id:t.id||"",price:t.price||0,item_category:t.category||"",quantity:t.quantity||1}))}})}var We=class extends h{static properties={product:{type:String},buttonText:{type:String,attribute:"button-text"},cartUrl:{type:String,attribute:"cart-url"},max:{type:Number},_quantity:{state:!0},_busy:{state:!0},_message:{state:!0},_error:{state:!0},_count:{state:!0}};static styles=[h.baseStyles,m`
      .quantity {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1rem;
      }
      .quantity input {
        width: 7rem;
      }
      .buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        align-items: center;
      }
      button,
      a.secondary {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        cursor: pointer;
      }
      svg {
        flex: none;
      }
      .message {
        margin-bottom: 1rem;
      }
      .count {
        font-style: italic;
      }
    `];#e=null;constructor(){super(),this.product="",this.buttonText="",this.cartUrl="/warenkorb/#focus",this.max=100,this._quantity=1,this._busy=!1,this._message="",this._error="",this._count=0}connectedCallback(){super.connectedCallback(),this.#e=S(R,e=>{e.detail&&typeof e.detail.count=="number"&&(this._count=e.detail.count)}),U().then(e=>{this._count=Number(e.cart_pos_count)||0}).catch(()=>{}),this.product&&Jt(this.product)}disconnectedCallback(){super.disconnectedCallback(),this.#e&&this.#e(),this.#e=null}#s(e){let t=Math.max(1,Math.min(this.max,Math.round(Number(e)||1)));return this._quantity=t,t}async add(){if(!this._busy){this._busy=!0,this._error="",this._message="";try{let e=await u("inCart",{product:this.product,quantity:this._quantity}),t=Number(e.cartPosCount)||0;this._count=t,this._message=r("addToCart.added"),x(R,{count:t}),t>0&&Xt(this.product,this._quantity)}catch(e){this._error=r(e.code||"SHOP_API_ERROR")}finally{this._busy=!1}}}render(){return n`
      <div class="quantity">
        <input
          id="quantity"
          type="number"
          min="1"
          max=${this.max}
          step="1"
          class=${this.cls("input")}
          aria-label=${r("addToCart.quantity")}
          .value=${String(this._quantity)}
          @change=${e=>{e.target.value=String(this.#s(e.target.value))}}
        >
        <span>${r("addToCart.unit")}</span>
      </div>

      ${this._error?n`<div class="message ${this.cls("alertError")}" role="alert">${this._error}</div>`:l}
      ${this._message?n`<div class="message ${this.cls("alertSuccess")}" role="status">${this._message}</div>`:l}

      <div class="buttons">
        <button
          type="button"
          class=${this.cls("buttonPrimary")}
          ?disabled=${this._busy||!this.product}
          @click=${()=>this.add()}
        >
          ${this.#t()}
          ${this._busy?r("addToCart.pending"):this.buttonText||r("addToCart.submit")}
        </button>

        ${this._count>0?n`
              <a class="secondary ${this.cls("buttonSecondary")}" href=${this.cartUrl}>
                ${this.#r()}
                <span>${r("addToCart.viewCart")}</span>
                <span class="count">(${this._count})</span>
              </a>
            `:l}
      </div>
    `}#t(){return n`
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor"
           viewBox="0 0 16 16" aria-hidden="true">
        <path d="M9 5.5a.5.5 0 0 0-1 0V7H6.5a.5.5 0 0 0 0 1H8v1.5a.5.5 0 0 0 1 0V8h1.5a.5.5 0 0 0 0-1H9z"/>
        <path d="M.5 1a.5.5 0 0 0 0 1h1.11l.401 1.607 1.498 7.985A.5.5 0 0 0 4 12h1a2 2 0 1 0 0 4 2 2 0 0 0 0-4h7a2 2 0 1 0 0 4 2 2 0 0 0 0-4h1a.5.5 0 0 0 .491-.408l1.5-8A.5.5 0 0 0 14.5 3H2.89l-.405-1.621A.5.5 0 0 0 2 1zm3.915 10L3.102 4h10.796l-1.313 7zM6 14a1 1 0 1 1-2 0 1 1 0 0 1 2 0m7 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0"/>
      </svg>
    `}#r(){return n`
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor"
           viewBox="0 0 16 16" aria-hidden="true">
        <path d="M11.354 6.354a.5.5 0 0 0-.708-.708L8 8.293 6.854 7.146a.5.5 0 1 0-.708.708l1.5 1.5a.5.5 0 0 0 .708 0z"/>
        <path d="M.5 1a.5.5 0 0 0 0 1h1.11l.401 1.607 1.498 7.985A.5.5 0 0 0 4 12h1a2 2 0 1 0 0 4 2 2 0 0 0 0-4h7a2 2 0 1 0 0 4 2 2 0 0 0 0-4h1a.5.5 0 0 0 .491-.408l1.5-8A.5.5 0 0 0 14.5 3H2.89l-.405-1.621A.5.5 0 0 0 2 1zm3.915 10L3.102 4h10.796l-1.313 7zM6 14a1 1 0 1 1-2 0 1 1 0 0 1 2 0m7 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0"/>
      </svg>
    `}};customElements.define("shop-add-to-cart",We);function _(s){return s==null?"":String(s)}function oe(s){return{id:_(s.shipto_id),name:_(s.shiptoname),street:_(s.shiptostreet),zipcode:_(s.shiptozipcode),city:_(s.shiptocity),country:_(s.shiptocountry),email:_(s.shiptoemail),phone:_(s.shiptophone),used:s.used===!0||Number(s.used)>0}}function ss(s){return{id:_(s.shipto_default),name:_(s.shipto_name),street:_(s.shipto_street),zipcode:_(s.shipto_zipcode),city:_(s.shipto_city),country:_(s.shipto_country),email:_(s.shipto_email),phone:_(s.shipto_phone),used:!1}}function Ee(s){return{street:_(s.street),zipcode:_(s.zipcode),city:_(s.city),country:_(s.country)}}function Q(s,e){return s.name.localeCompare(e.name,void 0,{sensitivity:"base"})}function is(s){return s?Object.values(s).map(oe).sort(Q):[]}function rs(){return{id:"",name:"",street:"",zipcode:"",city:"",country:"",email:"",phone:"",used:!1}}function Ke(s){return{name:s.name,street:s.street,city:s.city,zipcode:s.zipcode,country:s.country,email:s.email,phone:s.phone}}function as(s,e){return[_(s),_(e)].filter(Boolean).join(" ")}function C(s){return[[s.zipcode,s.city].filter(Boolean).join(" "),s.country].filter(Boolean).join(", ")}var Ze=class extends h{static properties={current:{type:String},overviewUrl:{type:String,attribute:"overview-url"},profileUrl:{type:String,attribute:"profile-url"},addressUrl:{type:String,attribute:"address-url"},orderUrl:{type:String,attribute:"order-url"},paymentUrl:{type:String,attribute:"payment-url"},showPayment:{type:Boolean,attribute:"show-payment"},_greeting:{state:!0}};static styles=[h.baseStyles,m`
      ul {
        list-style: none;
        margin: 0;
        padding: 0;
        border: 1px solid var(--shop-border-color, #dee2e6);
        border-radius: var(--shop-radius, 0.375rem);
        overflow: hidden;
      }
      li + li {
        border-top: 1px solid var(--shop-border-color, #dee2e6);
      }
      li {
        padding: 0.75rem 1rem;
      }
      .greeting {
        font-weight: 600;
        background: var(--shop-input-bg, #f8f9fa);
        min-height: 1.5em;
      }
      a {
        color: inherit;
      }
      [aria-current] {
        font-weight: 600;
      }
    `];#e=[];constructor(){super(),this.current="",this.overviewUrl="/pers\xF6nliche-daten/",this.profileUrl="/pers\xF6nliches-profil/",this.addressUrl="/adressen/",this.orderUrl="/bestellungen/",this.paymentUrl="/zahlungsarten/",this.showPayment=!1,this._greeting=""}connectedCallback(){super.connectedCallback(),this.#e.push(S(ne,e=>{let t=e.detail||{};this._greeting=as(t.salutation,t.name)})),this.#e.push(S(P,e=>{e.detail&&e.detail.account===!1&&(this._greeting="")})),U().then(e=>{(!e||!e.account)&&(this._greeting="")}).catch(()=>{})}disconnectedCallback(){super.disconnectedCallback();for(let e of this.#e)e();this.#e=[]}#s(){let e=[{key:"overview",url:this.overviewUrl,label:r("account.nav.overview")},{key:"profile",url:this.profileUrl,label:r("account.nav.profile")},{key:"address",url:this.addressUrl,label:r("account.nav.address")},{key:"order",url:this.orderUrl,label:r("account.nav.order")}];return this.showPayment&&e.push({key:"payment",url:this.paymentUrl,label:r("account.nav.payment")}),e}render(){return n`
      <nav part="nav" aria-label=${r("account.nav.label")}>
        <ul>
          <li class="greeting" part="greeting">
            ${this._greeting?`${r("account.greeting")}, ${this._greeting}`:l}
          </li>
          ${this.#s().map(e=>n`
              <li part="item">
                ${e.key===this.current?n`<span aria-current="page">${e.label}</span>`:n`<a href=${e.url}>${e.label}</a>`}
              </li>
            `)}
        </ul>
      </nav>
    `}};customElements.define("shop-account-nav",Ze);var A=class extends h{static properties={loginUrl:{type:String,attribute:"login-url"},heading:{type:String},_state:{state:!0},_error:{state:!0}};static accountStyles=m`
    .section {
      font-size: 1.15rem;
      font-weight: 600;
      margin: 2.5rem 0 1rem;
      padding-bottom: 0.5rem;
      border-bottom: 1px solid var(--shop-border-color, #dee2e6);
    }
    .section:first-child {
      margin-top: 0;
    }
    .hint {
      margin: 0 0 1rem;
    }
    .cards {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(min(100%, 18rem), 1fr));
      gap: 1.25rem;
    }
    .card {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
      padding: 1.25rem;
      border: 1px solid var(--shop-border-color, #dee2e6);
      border-radius: var(--shop-radius, 0.375rem);
    }
    .card-title {
      font-size: 1.05rem;
      font-weight: 600;
      margin: 0 0 0.5rem;
      padding-bottom: 0.5rem;
      border-bottom: 1px solid var(--shop-border-color, #dee2e6);
    }
    .card .actions {
      margin-top: auto;
      padding-top: 1rem;
      gap: 0.5rem;
    }
    .shop-message {
      margin-top: 1rem;
      max-width: var(--shop-form-width, 32rem);
    }
    .shop-message:empty {
      display: none;
    }
  `;#e=null;constructor(){super(),this.loginUrl="/login/",this.heading="",this._state="loading",this._error=""}connectedCallback(){super.connectedCallback(),this.#e=S(P,()=>{D().catch(()=>{}).then(()=>this.reload())}),this.reload()}disconnectedCallback(){super.disconnectedCallback(),this.#e&&this.#e(),this.#e=null}async load(){}renderAccount(){return l}async reload(){this._state="loading",this._error="";try{let e=await U();if(!e||!e.account){this._state="anonymous";return}await this.load(),this._state="ready"}catch(e){try{let t=await D();if(!t||!t.account){this._state="anonymous";return}}catch{}this._error=r(e&&e.code||"SHOP_API_ERROR"),this._state="error"}}announce(e,t){x(ne,{salutation:e||"",name:t||""})}render(){return n`
      ${this.heading?n`<h2 class=${this.cls("heading")} part="heading">${this.heading}</h2>`:l}
      ${this._state==="ready"?this.renderAccount():this.renderState()}
    `}renderState(){return this._state==="loading"?n`<p class=${this.cls("muted")} part="loading">${r("account.loading")}</p>`:this._state==="anonymous"?n`
        <div part="anonymous">
          <p class="hint">${r("account.anonymous")}</p>
          <a class=${this.cls("buttonPrimary")} part="login-link" href=${this.loginUrl}>
            ${r("account.login")}
          </a>
        </div>
      `:n`
      <div class="shop-message" role="alert">
        <div class=${this.cls("alertError")} part="error">${this._error}</div>
      </div>
      <div class="actions">
        <button class=${this.cls("button")} part="retry" @click=${()=>this.reload()}>
          ${r("account.retry")}
        </button>
      </div>
    `}};var Ye=class extends A{static properties={profileUrl:{type:String,attribute:"profile-url"},passwordUrl:{type:String,attribute:"password-url"},addressUrl:{type:String,attribute:"address-url"},deliveryUrl:{type:String,attribute:"delivery-url"},paymentUrl:{type:String,attribute:"payment-url"},_data:{state:!0}};static styles=[h.baseStyles,A.accountStyles,m`
      .cards {
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 20rem), 1fr));
      }
      /* description_long kommt aus der Datenbank und ist oft mehrzeilig. */
      .payment-description {
        white-space: pre-line;
      }
    `];constructor(){super(),this.profileUrl="/pers\xF6nliches-profil/",this.passwordUrl="",this.addressUrl="/adressen/",this.deliveryUrl="",this.paymentUrl="/zahlungsarten/",this._data=null}async load(){let e=await u("personalOverview");this._data={name:e.name||"",email:e.email||"",salutation:e.salutation||"",billing:Ee(e),shipto:e.shipto_name?ss(e):null,payment:e.payment_method||null},this.announce(this._data.salutation,this._data.name)}#e(e,t,i){return n`
      <div class="card" part="card">
        <div class="card-title" part="card-title">${e}</div>
        ${t}
        <div class="actions">
          ${i.map(([a,o])=>n`<a class=${this.cls("button")} part="card-link" href=${o}>${a}</a>`)}
        </div>
      </div>
    `}renderAccount(){let e=this._data;if(!e)return l;let t=this.passwordUrl||this.profileUrl,i=this.deliveryUrl||this.addressUrl;return n`
      <div class="cards" part="cards">
        ${this.#e(r("overview.profile"),n`<div>${e.name}</div>
            <div>${e.email}</div>`,[[r("account.edit"),this.profileUrl],[r("overview.changePassword"),t]])}
        ${this.#e(r("overview.payment"),e.payment?n`<div>${e.payment.description}</div>
                <div class="payment-description">${e.payment.description_long}</div>`:n`<div>${r("overview.paymentNone")}</div>`,[[r("account.edit"),this.paymentUrl]])}
        ${this.#e(r("overview.billingAddress"),n`<div>${e.billing.street}</div>
            <div>${C(e.billing)}</div>`,[[r("account.edit"),this.addressUrl]])}
        ${this.#e(r("overview.deliveryAddress"),e.shipto?n`<div>${e.shipto.name}</div>
                <div>${e.shipto.street}</div>
                <div>${C(e.shipto)}</div>`:n`<div>${r("overview.deliveryIsBilling")}</div>`,[[r("account.edit"),i]])}
      </div>
    `}};customElements.define("shop-account-overview",Ye);var Qe=class extends A{static properties={passwordHash:{type:String,attribute:"password-hash"},_profile:{state:!0},_salutations:{state:!0},_busy:{state:!0},_messages:{state:!0}};static styles=[h.baseStyles,A.accountStyles,m`
      form + .section {
        margin-top: 3rem;
      }
      .hint {
        margin: 0 0 1rem;
        max-width: var(--shop-form-width, 32rem);
      }
    `];#e=!1;constructor(){super(),this.passwordHash="#Passwort",this._profile=null,this._salutations=[],this._busy="",this._messages={}}async load(){let e=await u("personalProfil");this._salutations=e&&e.salutations||[],this._profile={naturalPerson:e.natural_person?"true":"false",companyName:e.company_name||"",name:e.name||"",phone:e.phone||"",email:e.email||"",salutation:e.salutation||""},this.announce(this._profile.salutation,this._profile.name)}updated(e){if(super.updated(e),this.#e||this._state!=="ready"||!this.passwordHash||window.location.hash!==this.passwordHash)return;this.#e=!0;let t=this.$("password-section");t&&t.scrollIntoView({block:"start"})}#s(e,t){this._profile={...this._profile,[e]:t}}#t(e,t,i){this._messages={...this._messages,[e]:{text:r(t),ok:i}}}#r(e){let t={...this._messages};delete t[e],this._messages=t}#a(e){let t=this._messages[e];return t?n`
      <div class="shop-message" role="alert" aria-live="polite">
        <div
          class=${t.ok?this.cls("alertSuccess"):this.cls("alertError")}
          part=${t.ok?"success":"error"}
        >
          ${t.text}
        </div>
      </div>
    `:l}#i(e,t,i={}){return n`
      <div class="field" part="field">
        <label class=${this.cls("label")} part="label" for=${e}>
          ${t}${i.required?"*":""}
        </label>
        <input
          class=${this.cls("input")}
          part="input"
          id=${e}
          name=${e}
          type=${i.type||"text"}
          autocomplete=${i.autocomplete||"off"}
          .value=${i.value===void 0?"":i.value}
          @input=${i.onInput||void 0}
          ?required=${!!i.required}
        />
      </div>
    `}#n(e,t,i){return n`
      <div class="field" part="field">
        <label class=${this.cls("label")} part="label" for=${e}>${t}*</label>
        <input
          class=${this.cls("input")}
          part="input"
          id=${e}
          name=${e}
          type="password"
          autocomplete=${i}
          required
        />
      </div>
    `}#l(e){let t=this._busy===e;return n`
      <div class="actions">
        <button
          class=${this.cls("buttonSecondary")}
          part="submit"
          type="submit"
          ?disabled=${t}
        >
          ${t?r("account.saving"):r("account.save")}
        </button>
      </div>
    `}async#o(e,t,i,a,o){this._busy=e,this.#r(e);try{await u(t,i),this.#t(e,a,!0),o&&o()}catch(c){this.#t(e,c&&c.code||"SHOP_API_ERROR",!1)}finally{this._busy=""}}#d(e){if(e.preventDefault(),this._busy)return;let t=this._profile;if(t.naturalPerson==="false"&&!t.companyName.trim()){this.#t("profile","profile.companyMissing",!1);return}if(!t.name.trim()){this.#t("profile","profile.nameMissing",!1);return}this.#o("profile","updatePersonalProfil",{name:t.name,phone:t.phone,salutation:t.salutation,natural_person:t.naturalPerson,company_name:t.companyName},"account.saved",()=>this.announce(t.salutation,t.name))}#u(e){if(e.preventDefault(),this._busy)return;let t=this.$("confirm-password-email");if(!this._profile.email.trim()){this.#t("email","profile.emailMissing",!1);return}if(!t.value){this.#t("email","profile.passwordMissing",!1);return}this.#o("email","updateEmail",{email:this._profile.email,password:t.value},"profile.emailSaved",()=>{t.value=""})}#h(e){if(e.preventDefault(),this._busy)return;let t=this.$("old-password"),i=this.$("new-password"),a=this.$("confirm-password");if(!i.value){this.#t("password","profile.newPasswordMissing",!1);return}if(!a.value){this.#t("password","profile.repeatMissing",!1);return}if(i.value!==a.value){this.#t("password","register.passwordMismatch",!1);return}if(!t.value){this.#t("password","profile.passwordMissing",!1);return}this.#o("password","updatePassword",{old_password:t.value,new_password:i.value},"profile.passwordSaved",()=>{t.value="",i.value="",a.value=""})}renderAccount(){let e=this._profile;if(!e)return l;let t=e.naturalPerson==="false";return n`
      <form class=${this.cls("form")} part="form" @submit=${this.#d} novalidate>
        <div class="section" part="section">${r("profile.personal")}</div>
        <div class="fields">
          <div class="field" part="field">
            <label class=${this.cls("label")} part="label" for="account-type">
              ${r("register.accountType")}*
            </label>
            <select
              class=${this.cls("select")}
              part="select"
              id="account-type"
              name="account-type"
              @change=${i=>this.#s("naturalPerson",i.target.value)}
            >
              <option value="true" .selected=${e.naturalPerson==="true"}>
                ${r("register.private")}
              </option>
              <option value="false" .selected=${e.naturalPerson==="false"}>
                ${r("register.business")}
              </option>
            </select>
          </div>

          ${t?this.#i("company-name",r("register.companyName"),{required:!0,autocomplete:"organization",value:e.companyName,onInput:i=>this.#s("companyName",i.target.value)}):l}

          <div class="field" part="field">
            <label class=${this.cls("label")} part="label" for="salutation">
              ${r("register.salutation")}
            </label>
            <select
              class=${this.cls("select")}
              part="select"
              id="salutation"
              name="salutation"
              autocomplete="honorific-prefix"
              @change=${i=>this.#s("salutation",i.target.value)}
            >
              <option value="" .selected=${!e.salutation}></option>
              ${this._salutations.map(i=>n`<option
                  value=${i.translation}
                  .selected=${i.translation===e.salutation}
                >
                  ${i.translation}
                </option>`)}
            </select>
          </div>

          ${this.#i("name",t?r("register.contactName"):r("register.name"),{required:!0,autocomplete:"name",value:e.name,onInput:i=>this.#s("name",i.target.value)})}
          ${this.#i("phone",r("register.phone"),{type:"tel",autocomplete:"tel",value:e.phone,onInput:i=>this.#s("phone",i.target.value)})}
        </div>
        ${this.#l("profile")} ${this.#a("profile")}
      </form>

      <form class=${this.cls("form")} part="form" @submit=${this.#u} novalidate>
        <div class="section" part="section">${r("profile.credentials")}</div>
        <div class="fields">
          ${this.#i("email",r("register.email"),{type:"email",autocomplete:"email",required:!0,value:e.email,onInput:i=>this.#s("email",i.target.value)})}
        </div>
        <p class="hint ${this.cls("muted")}">${r("profile.confirmHint")}</p>
        <div class="fields">
          ${this.#n("confirm-password-email",r("profile.currentPassword"),"current-password")}
        </div>
        ${this.#l("email")} ${this.#a("email")}
      </form>

      <form class=${this.cls("form")} part="form" @submit=${this.#h} novalidate>
        <div class="section" part="section" id="password-section">${r("profile.password")}</div>
        <p class="hint ${this.cls("muted")}">${r("profile.confirmHint")}</p>
        <div class="fields">
          ${this.#n("old-password",r("profile.currentPassword"),"current-password")}
          ${this.#n("new-password",r("profile.newPassword"),"new-password")}
          ${this.#n("confirm-password",r("profile.repeatPassword"),"new-password")}
        </div>
        ${this.#l("password")} ${this.#a("password")}
      </form>
    `}};customElements.define("shop-account-profile",Qe);var Je=class extends A{static properties={_methods:{state:!0},_selected:{state:!0},_busy:{state:!0},_message:{state:!0}};static styles=[h.baseStyles,A.accountStyles,m`
      fieldset {
        border: 0;
        margin: 0;
        padding: 0;
      }
      legend {
        padding: 0;
      }
      .method {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        gap: 0.25rem 0.75rem;
        padding: 1rem 0;
      }
      .method + .method {
        border-top: 1px solid var(--shop-border-color, #dee2e6);
      }
      .method input {
        margin-top: 0.25rem;
      }
      .method label {
        font-weight: 600;
        cursor: pointer;
      }
      .method .description {
        grid-column: 2;
        white-space: pre-line;
      }
    `];constructor(){super(),this._methods=[],this._selected="",this._busy=!1,this._message=null}async load(){let e=await u("personalPayment");this._methods=e&&e.payment_methods||[],this._selected=e&&e.default_payment!==null&&e.default_payment!==void 0?String(e.default_payment):"",this.announce("",e&&e.name||"")}async#e(e){if(this._busy||e===this._selected)return;let t=this._selected;this._selected=e,this._busy=!0,this._message=null;try{await u("changePaymentMethod",{payment_id:e}),this._message={text:r("payment.saved"),ok:!0}}catch(i){this._selected=t,this._message={text:r(i&&i.code||"SHOP_API_ERROR"),ok:!1}}finally{this._busy=!1}}renderAccount(){return this._methods.length?n`
      <fieldset part="methods">
        <legend class="section" part="section">${r("payment.heading")}</legend>
        ${k(this._methods,e=>e.id,e=>{let t=String(e.id);return n`
              <div class="method" part="method">
                <input
                  class=${this.cls("check")}
                  part="radio"
                  type="radio"
                  name="payment-method"
                  id=${`payment-${t}`}
                  value=${t}
                  .checked=${this._selected===t}
                  ?disabled=${this._busy}
                  @change=${()=>this.#e(t)}
                />
                <label class=${this.cls("label")} part="label" for=${`payment-${t}`}>
                  ${e.description}
                </label>
                <div class="description">${e.description_long}</div>
              </div>
            `})}
      </fieldset>

      ${this._message?n`<div class="shop-message" role="status" aria-live="polite">
            <div
              class=${this._message.ok?this.cls("alertSuccess"):this.cls("alertError")}
              part=${this._message.ok?"success":"error"}
            >
              ${this._message.text}
            </div>
          </div>`:l}
    `:n`<p class=${this.cls("muted")}>${r("payment.none")}</p>`}};customElements.define("shop-account-payment",Je);var ns={de:"de-DE",en:"en-GB"},Is=/^(\d{4})-(\d{2})-(\d{2})$/;function Xe(s){if(!s)return"";let e=String(s).trim(),t=Is.exec(e),i=t?new Date(Number(t[1]),Number(t[2])-1,Number(t[3])):new Date(e);if(Number.isNaN(i.getTime()))return e;let a=(document.documentElement.lang||"de").slice(0,2).toLowerCase();return i.toLocaleDateString(ns[a]||ns.de)}var et=class extends A{static properties={apiUrl:{type:String,attribute:"api-url"},productUrl:{type:String,attribute:"product-url"},thumbnailUrl:{type:String,attribute:"thumbnail-url"},_orders:{state:!0},_detail:{state:!0},_busy:{state:!0}};static styles=[h.baseStyles,A.accountStyles,m`
      .order {
        padding: 1.25rem;
        margin-bottom: 1rem;
        border: 1px solid var(--shop-border-color, #dee2e6);
        border-radius: var(--shop-radius, 0.375rem);
        cursor: pointer;
      }
      .order-title {
        font-size: 1.05rem;
        font-weight: 600;
        margin: 0 0 0.75rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid var(--shop-border-color, #dee2e6);
      }
      .order-line {
        margin-bottom: 0.5rem;
      }
      .detail-head {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 18rem), 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
      }
      .facts {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        gap: 0.35rem 1rem;
      }
      .facts dt {
        font-weight: 600;
      }
      .facts dd {
        margin: 0;
      }
      .position {
        display: grid;
        grid-template-columns: minmax(0, 10rem) minmax(0, 1fr);
        gap: 1.25rem;
        align-items: start;
        padding: 1rem;
        margin-bottom: 0.75rem;
        border: 1px solid var(--shop-border-color, #dee2e6);
        border-radius: var(--shop-radius, 0.375rem);
      }
      @media (max-width: 40rem) {
        .position {
          grid-template-columns: minmax(0, 1fr);
        }
      }
      .thumb {
        display: block;
        width: 100%;
        height: auto;
      }
      .position h3 {
        font-size: 1.05rem;
        margin: 0 0 0.75rem;
      }
      /* Sieht aus wie ein Link, ist aber keiner: das Ziel loest erst
         getProductLink auf. */
      .link-button {
        font: inherit;
        color: inherit;
        background: none;
        border: 0;
        padding: 0;
        text-align: left;
        cursor: pointer;
        text-decoration: underline;
        text-underline-offset: 0.2em;
      }
      .note {
        margin-top: 1.5rem;
      }
      form {
        margin-top: 1rem;
      }
    `];constructor(){super(),this.apiUrl="/shop-api/",this.productUrl="/produkt/",this.thumbnailUrl="/images/thumbnails/",this._orders=[],this._detail=null,this._busy=!1}async load(){let e=await u("personalOrders");this._orders=e&&e.orders||[],this._detail=null,this.announce("",e&&e.name||"")}#e(e,t){let i=e.composedPath();for(let a of i){if(a===e.currentTarget)break;let o=a.tagName;if(o==="BUTTON"||o==="A"||o==="INPUT"||o==="FORM")return}this.#s(t)}async#s(e){if(!this._busy){this._busy=!0,this._error="";try{this._detail=await u("personalOrder",{id:e})}catch(t){this._error=r(t&&t.code||"SHOP_API_ERROR"),this._state="error"}finally{this._busy=!1}}}async#t(e){if(e)try{let t=await u("getProductLink",{product:e});t&&t.hyperlink&&(window.location.href=this.productUrl+t.hyperlink)}catch{}}#r(e){return n`
      <form action=${`${this.apiUrl}?action=downloadInvoice`} method="post" part="download">
        <input type="hidden" name="payment-id" value=${e} />
        <input type="hidden" name="content-type" value="application/pdf" />
        <button class=${this.cls("button")} type="submit">${r("orders.download")}</button>
      </form>
    `}#a(){return this._orders.length?n`
      <div part="orders">
        ${k(this._orders,e=>e.id,e=>n`
            <div
              class="order"
              part="order"
              @click=${t=>this.#e(t,e.id)}
            >
              <div class="order-title">
                <button class="link-button" @click=${()=>this.#s(e.id)}>
                  ${r("orders.from")} ${Xe(e.date)}
                </button>
              </div>
              <div class="order-line ${this.cls("muted")}">
                ${r("orders.positions")}: ${e.positions}
              </div>
              <div class="order-line">
                <strong>${r("orders.total")}:</strong>
                <strong>${E(e.amount)} ${e.currency}</strong>
                <span class=${this.cls("muted")}>${r("orders.grossSuffix")}</span>
              </div>
              ${this.#r(e.id)}
            </div>
          `)}
      </div>
    `:n`<p class=${this.cls("muted")} part="empty">${r("orders.empty")}</p>`}#i(){let e=this._detail,t=e.invoice||{},i=e.shipping||{},a=e.positions||[];return n`
      <div class="actions" style="margin-top:0">
        <button
          class=${this.cls("button")}
          part="back"
          @click=${()=>{this._detail=null}}
        >
          ${r("orders.back")}
        </button>
      </div>

      <div class="detail-head">
        <div>
          <div class="section" part="section">${r("orders.details")}</div>
          <dl class="facts">
            <dt>${r("orders.number")}</dt>
            <dd>${t.invnumber}</dd>
            <dt>${r("orders.date")}</dt>
            <dd>${Xe(t.invdate)}</dd>
            <dt>${r("orders.total")}</dt>
            <dd>${E(t.invtotal)} ${t.currency}</dd>
          </dl>
          ${this.#r(t.id)}
        </div>
        <div>
          <div class="section" part="section">${r("orders.deliveryAddress")}</div>
          <div>${i.name}</div>
          <div>${i.street}</div>
          <div>${C(i)}</div>
        </div>
      </div>

      ${k(a,o=>`${o.parts_id}-${o.runningnumber}`,o=>n`
          <div class="position" part="position">
            ${o.thumbnail?n`<img
                  class="thumb"
                  part="thumb"
                  src=${this.thumbnailUrl+o.thumbnail}
                  alt=""
                  loading="lazy"
                />`:n`<div></div>`}
            <div>
              <h3>
                <button class="link-button" @click=${()=>this.#t(o.parts_id)}>
                  ${o.description}
                </button>
              </h3>
              <div class="order-line">
                <strong>${r("orders.quantity")}:</strong> ${o.qty}
              </div>
              <div class="order-line">
                <strong>${r("orders.price")}:</strong>
                ${E(o.linetotal)} ${t.currency}*
                <span class=${this.cls("muted")}>
                  (${E(o.sellprice)} ${t.currency}* /
                  ${r("orders.perUnit")})
                </span>
              </div>
            </div>
          </div>
        `)}

      <div class="note ${this.cls("muted")}">${r("orders.grossNote")}</div>
    `}renderAccount(){return this._detail?this.#i():this.#a()}};customElements.define("shop-account-orders",et);var tt=class extends A{static properties={_billing:{state:!0},_addresses:{state:!0},_defaultId:{state:!0},_form:{state:!0},_busy:{state:!0},_billingMessage:{state:!0},_formMessage:{state:!0},_listMessage:{state:!0}};static styles=[h.baseStyles,A.accountStyles,m`
      .default-card {
        max-width: 24rem;
      }
      .badge {
        font-size: 0.875rem;
        min-height: 1.25em;
      }
      .address-lines {
        margin-bottom: 0.5rem;
      }
      .cards {
        margin-top: 1.25rem;
      }
      .used-hint {
        font-size: 0.875rem;
      }
    `];constructor(){super(),this._billing=null,this._addresses=[],this._defaultFallback=null,this._defaultId="",this._form=null,this._busy=!1,this._billingMessage=null,this._formMessage=null,this._listMessage=null}async load(){let e=await u("accountAddresses");this._billing=Ee(e),this._addresses=is(e.shipping_addresses),this._defaultFallback=e.shiptoname?oe({...e,shipto_id:e.shipto_default}):null,this._defaultId=e.shipto_default===null||e.shipto_default===void 0?"":String(e.shipto_default),this._form=null,this._billingMessage=null,this._formMessage=null,this._listMessage=null,this.announce(e.salutation,e.name)}#e(){return this._defaultId?this._addresses.find(e=>e.id===this._defaultId)||this._defaultFallback:null}#s(e,t){this._billing={...this._billing,[e]:t}}#t(e,t){this._form={...this._form,address:{...this._form.address,[e]:t}}}#r(e,t){return{text:r(e),ok:t}}#a(e,t="alert"){return e?n`
      <div class="shop-message" role=${t} aria-live="polite">
        <div
          class=${e.ok?this.cls("alertSuccess"):this.cls("alertError")}
          part=${e.ok?"success":"error"}
        >
          ${e.text}
        </div>
      </div>
    `:l}#i(e,t,i,a,o={}){return n`
      <div class="field" part="field">
        <label class=${this.cls("label")} part="label" for=${e}>
          ${t}${o.required?"*":""}
        </label>
        <input
          class=${this.cls("input")}
          part="input"
          id=${e}
          name=${e}
          type=${o.type||"text"}
          autocomplete=${o.autocomplete||"off"}
          .value=${i}
          @input=${a}
          ?required=${!!o.required}
        />
      </div>
    `}#n(e,t){return t&&!e.name.trim()?"addresses.nameMissing":e.street.trim()?e.city.trim()?e.zipcode.trim()?e.country.trim()?"":"addresses.countryMissing":"addresses.postcodeMissing":"addresses.cityMissing":"addresses.streetMissing"}async#l(e){if(e.preventDefault(),this._busy)return;let t=this.#n(this._billing,!1);if(t){this._billingMessage=this.#r(t,!1);return}this._busy=!0,this._billingMessage=null;try{await u("updateAddress",{street:this._billing.street,city:this._billing.city,zipcode:this._billing.zipcode,country:this._billing.country}),this._billingMessage=this.#r("addresses.saved",!0)}catch(i){this._billingMessage=this.#r(i&&i.code||"SHOP_API_ERROR",!1)}finally{this._busy=!1}}async#o(e){if(e.preventDefault(),this._busy||!this._form)return;let{mode:t,address:i}=this._form,a=this.#n(i,!0);if(a){this._formMessage=this.#r(a,!1);return}this._busy=!0,this._formMessage=null;try{if(t==="new"){let o=await u("newDeliveryAddress",Ke(i)),c={...i,id:String(o&&o.shipto_id||""),used:!1};this._addresses=[...this._addresses,c].sort(Q)}else await u("updateDeliveryAddress",{shipto_id:i.id,...Ke(i)}),this._addresses=this._addresses.map(o=>o.id===i.id?{...i,used:o.used}:o).sort(Q);this._form=null}catch(o){this._formMessage=this.#r(o&&o.code||"SHOP_API_ERROR",!1)}finally{this._busy=!1}}async#d(e){if(!this._busy&&window.confirm(r("addresses.confirmRemove"))){this._busy=!0,this._listMessage=null;try{this._defaultId===e.id&&(await u("takeBillAddress"),this._defaultId=""),await u("removeDeliveryAddress",{shipto_id:e.id}),this._addresses=this._addresses.filter(t=>t.id!==e.id),this._form&&this._form.address.id===e.id&&(this._form=null)}catch(t){this._listMessage=this.#r(t&&t.code||"SHOP_API_ERROR",!1)}finally{this._busy=!1}}}async#u(e){if(!this._busy){this._busy=!0,this._listMessage=null;try{await u("standardDeliveryAddress",{shipto_id:e.id}),this._defaultId=e.id}catch(t){this._listMessage=this.#r(t&&t.code||"SHOP_API_ERROR",!1)}finally{this._busy=!1}}}async#h(){if(!this._busy){this._busy=!0,this._listMessage=null;try{await u("takeBillAddress"),this._defaultId=""}catch(e){this._listMessage=this.#r(e&&e.code||"SHOP_API_ERROR",!1)}finally{this._busy=!1}}}#p(){let e=this._billing;return n`
      <form class=${this.cls("form")} part="form" @submit=${this.#l} novalidate>
        <div class="section" part="section">${r("addresses.billing")}</div>
        <div class="fields">
          ${this.#i("street",r("register.street"),e.street,t=>this.#s("street",t.target.value),{required:!0,autocomplete:"street-address"})}
          ${this.#i("city",r("register.city"),e.city,t=>this.#s("city",t.target.value),{required:!0,autocomplete:"address-level2"})}
          ${this.#i("postcode",r("register.postcode"),e.zipcode,t=>this.#s("zipcode",t.target.value),{required:!0,autocomplete:"postal-code"})}
          ${this.#i("country",r("register.country"),e.country,t=>this.#s("country",t.target.value),{required:!0,autocomplete:"country-name"})}
        </div>
        <div class="actions">
          <button
            class=${this.cls("buttonSecondary")}
            part="submit"
            type="submit"
            ?disabled=${this._busy}
          >
            ${this._busy?r("account.saving"):r("account.save")}
          </button>
        </div>
        ${this.#a(this._billingMessage)}
      </form>
    `}#m(){let e=this.#e();return e?n`
      <div class="card default-card" part="default-address">
        <div class="card-title">${e.name}</div>
        <div>${e.street}</div>
        <div>${C(e)}</div>
        ${e.email?n`<div>${e.email}</div>`:l}
        ${e.phone?n`<div>${e.phone}</div>`:l}
        <div class="actions">
          <button
            class=${this.cls("button")}
            part="take-billing"
            ?disabled=${this._busy}
            @click=${()=>this.#h()}
          >
            ${r("addresses.takeBilling")}
          </button>
        </div>
      </div>
    `:n`<p class="hint">${r("overview.deliveryIsBilling")}</p>`}#c(){let{mode:e,address:t}=this._form;return n`
      <form class=${this.cls("form")} part="form" @submit=${this.#o} novalidate>
        <div class="section" part="section">
          ${e==="new"?r("addresses.new"):r("addresses.edit")}
        </div>
        <div class="fields">
          ${this.#i("delivery-name",r("register.name"),t.name,i=>this.#t("name",i.target.value),{required:!0,autocomplete:"name"})}
          ${this.#i("delivery-street",r("register.street"),t.street,i=>this.#t("street",i.target.value),{required:!0,autocomplete:"street-address"})}
          ${this.#i("delivery-city",r("register.city"),t.city,i=>this.#t("city",i.target.value),{required:!0,autocomplete:"address-level2"})}
          ${this.#i("delivery-postcode",r("register.postcode"),t.zipcode,i=>this.#t("zipcode",i.target.value),{required:!0,autocomplete:"postal-code"})}
          ${this.#i("delivery-country",r("register.country"),t.country,i=>this.#t("country",i.target.value),{required:!0,autocomplete:"country-name"})}
          ${this.#i("delivery-email",r("register.email"),t.email,i=>this.#t("email",i.target.value),{type:"email",autocomplete:"email"})}
          ${this.#i("delivery-phone",r("register.phone"),t.phone,i=>this.#t("phone",i.target.value),{type:"tel",autocomplete:"tel"})}
        </div>
        <div class="actions">
          <button
            class=${this.cls("buttonSecondary")}
            part="submit"
            type="submit"
            ?disabled=${this._busy}
          >
            ${e==="new"?r("addresses.add"):r("addresses.save")}
          </button>
          <button
            class=${this.cls("button")}
            part="cancel"
            type="button"
            @click=${()=>{this._form=null,this._formMessage=null}}
          >
            ${r("account.cancel")}
          </button>
        </div>
        ${this.#a(this._formMessage)}
      </form>
    `}#g(e){let t=e.id===this._defaultId;return n`
      <div class="card" part="address">
        <div class="card-title">${e.name}</div>
        <div class="badge ${this.cls("muted")}">
          ${t?r("addresses.isDefault"):l}
        </div>
        <div class="address-lines">
          <div>${e.street}</div>
          <div>${C(e)}</div>
        </div>
        <div class="actions">
          <button
            class=${this.cls("button")}
            part="edit"
            ?disabled=${this._busy}
            @click=${()=>{this._form={mode:"edit",address:{...e}},this._formMessage=null}}
          >
            ${r("account.edit")}
          </button>
          ${e.used?l:n`<button
                class=${this.cls("button")}
                part="remove"
                ?disabled=${this._busy}
                @click=${()=>this.#d(e)}
              >
                ${r("addresses.remove")}
              </button>`}
          ${t?l:n`<button
                class=${this.cls("button")}
                part="make-default"
                ?disabled=${this._busy}
                @click=${()=>this.#u(e)}
              >
                ${r("addresses.makeDefault")}
              </button>`}
        </div>
        ${e.used?n`<div class="used-hint ${this.cls("muted")}">${r("addresses.usedHint")}</div>`:l}
      </div>
    `}renderAccount(){return this._billing?n`
      ${this.#p()}

      <div class="section" part="section">${r("addresses.standardDelivery")}</div>
      ${this.#m()}

      <div class="section" part="section">${r("addresses.available")}</div>
      ${this._form?this.#c():n`<div class="actions" style="margin-top:0">
            <button
              class=${this.cls("button")}
              part="add"
              ?disabled=${this._busy}
              @click=${()=>{this._form={mode:"new",address:rs()},this._formMessage=null}}
            >
              ${r("addresses.add")}
            </button>
          </div>`}
      ${this.#a(this._listMessage)}
      ${this._addresses.length?n`<div class="cards" part="addresses">
            ${k(this._addresses,e=>e.id,e=>this.#g(e))}
          </div>`:n`<p class="hint ${this.cls("muted")}">${r("addresses.none")}</p>`}
    `:l}};customElements.define("shop-account-addresses",tt);function g(s){return s==null?"":String(s)}function os(s){let e={registered:!!(s&&s.registered),salutations:s&&s.salutations||[],billing:null,defaultShipping:null,addresses:[]};return e.registered&&(e.billing={name:g(s.address&&s.address.name),street:g(s.address&&s.address.street),zipcode:g(s.address&&s.address.zipcode),city:g(s.address&&s.address.city),country:g(s.address&&s.address.country)},s.shipping&&s.shipping.name&&(e.defaultShipping={id:g(s.shipping.id),name:g(s.shipping.name),street:g(s.shipping.street),zipcode:g(s.shipping.zipcode),city:g(s.shipping.city),country:g(s.shipping.country),email:"",phone:"",used:!1}),e.addresses=(s.shipping_addresses||[]).map(oe).sort(Q)),e}function st(s){if(s.mode==="new"){let e=s.address||{};return{default:!1,id:null,name:g(e.name),street:g(e.street),city:g(e.city),zipcode:g(e.zipcode),country:g(e.country),email:g(e.email),phone:g(e.phone)}}return{default:!0,id:s.addressId?String(s.addressId):null}}function ls(s){let e={billing:{default:!1,salutation:g(s.salutation),account_type:g(s["account-type"]),name:g(s.name),street:g(s.street),city:g(s.city),zipcode:g(s.postcode),country:g(s.country),phone:g(s.phone),email:g(s.email)}};return s["add-delivery-address"]==="true"?e.shipping={default:!1,id:null,name:g(s["shipping-name"]),street:g(s["shipping-street"]),city:g(s["shipping-city"]),zipcode:g(s["shipping-postcode"]),country:g(s["shipping-country"]),email:g(s["shipping-email"]),phone:g(s["shipping-phone"])}:e.shipping={default:!0,id:null},e}function J(){return{id:"",name:"",street:"",zipcode:"",city:"",country:"",email:"",phone:""}}function cs(s){let e=new URLSearchParams(s||"").get("link");return e?e.trim():""}var it=class extends h{static properties={billingPage:{type:String,attribute:"billing-page"},canceledPage:{type:String,attribute:"canceled-page"},invoiceUrl:{type:String,attribute:"invoice-url"},loginUrl:{type:String,attribute:"login-url"},dataProtectionUrl:{type:String,attribute:"data-protection-url"},paypalImage:{type:String,attribute:"paypal-image"},heading:{type:String},_state:{state:!0},_error:{state:!0},_account:{state:!0},_mode:{state:!0},_selectedId:{state:!0},_form:{state:!0},_createAccount:{state:!0},_busy:{state:!0},_count:{state:!0},_accountExists:{state:!0}};static styles=[h.baseStyles,m`
      .layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 2rem;
        align-items: start;
      }
      @media (max-width: 62rem) {
        .layout {
          grid-template-columns: minmax(0, 1fr);
        }
      }
      .panel {
        border: 1px solid var(--shop-border-color, #dee2e6);
        border-radius: var(--shop-radius, 0.375rem);
        padding: 1.5rem;
      }
      .section-title {
        margin: 0 0 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid var(--shop-border-color, #dee2e6);
      }
      .block {
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid var(--shop-border-color, #dee2e6);
      }
      .block:first-of-type {
        margin-top: 0;
        padding-top: 0;
        border-top: 0;
      }
      .block-title {
        font-weight: 600;
        margin-bottom: 0.5rem;
      }
      .address p {
        margin: 0.25rem 0;
      }
      .choose {
        margin-top: 1.5rem;
        max-width: var(--shop-form-width, 32rem);
      }
      .account-switch {
        display: flex;
        gap: 0.5rem;
        align-items: center;
        margin-bottom: 1.5rem;
      }
      .account-switch label {
        margin: 0;
      }
      .buy {
        margin-top: 2rem;
      }
      .note {
        margin-top: 1rem;
      }
      .paypal img {
        display: block;
        max-width: 100%;
        height: auto;
      }
      .shop-message {
        margin-top: 1.5rem;
      }
    `];#e=[];#s=!1;constructor(){super(),this.billingPage="",this.canceledPage="",this.invoiceUrl="/rechnung/",this.loginUrl="/login/",this.dataProtectionUrl="/datenschutz/",this.paypalImage="/images/paypal-de.png",this.heading="",this._state="loading",this._error="",this._account=null,this._mode="saved",this._selectedId="",this._form=J(),this._createAccount=!1,this._busy=!1,this._accountExists=!1,this._count=null}connectedCallback(){super.connectedCallback(),this.#e.push(S(R,e=>this.#t(e.detail)),S(P,()=>this.#r())),this.#r()}disconnectedCallback(){super.disconnectedCallback();for(let e of this.#e)e();this.#e=[]}#t(e){!e||typeof e.count!="number"||(this._count=e.count,e.cart&&!this.#s&&(this.#s=!0,es(e.cart)))}async#r(){this._state="loading",this._error="";try{let e=await u("billingAndShipping",{lang:document.documentElement.lang});this._account=os(e),this._mode="saved",this._selectedId=this._account.defaultShipping?this._account.defaultShipping.id:"",this._form=J(),this._state="ready"}catch(e){this._error=r(e.code||"SHOP_API_ERROR"),this._state="error"}}#a(){return this._mode==="new"?this._form:this._selectedId?this._account.addresses.find(e=>e.id===this._selectedId)||this._account.defaultShipping:null}#i(e){let t=this._account.addresses.find(i=>i.id===e);this._form=t?{...t}:J()}#n(e,t){this._form={...this._form,[e]:t}}#l(){let e=this._form;return e.name.trim()?e.street.trim()?e.city.trim()?e.zipcode.trim()?e.country.trim()?"":r("register.country"):r("register.postcode"):r("register.city"):r("register.street"):r("register.name")}async#o(){if(this._busy||this._count===0)return;this._error="",this._accountExists=!1;let e=!this._account.registered,t=e?this.$("guest"):null,i;if(e){if(!t||!t.validate())return;i=ls(t.values())}else if(this._mode==="new"){let a=this.#l();if(a){this._error=r("register.required")+a;return}i=st({mode:"new",address:this._form})}else i=st({mode:"saved",addressId:this._selectedId});this._busy=!0;try{if(e){let o=await t.register();o&&o.shipto_id&&(i.shipping={default:!1,id:String(o.shipto_id)})}let a=await u("invoicing",{guest:e,adresses:i});window.location.href=this.#d(a)}catch(a){this._busy=!1,a.code==="ACCOUNT_EXISTS"?(this._accountExists=!0,this._error=r("register.accountExistsHint")):this._error=r(a.code||"SHOP_API_ERROR")}}#d(e){let t=new URLSearchParams({link:e&&e.ar_link||""});return(!e||e.email_status!=="success")&&t.set("mail","error"),`${this.invoiceUrl}?${t}#focus`}#u(){return`/shop-api/?${new URLSearchParams({action:"beginPayment",bill:this.billingPage,canceled:this.canceledPage})}`}render(){return this._state==="loading"?n`<p class=${this.cls("muted")} role="status">${r("checkout.loading")}</p>`:this._state==="error"?n`
        <div class=${this.cls("alertError")} role="alert">${this._error}</div>
        <p class="actions">
          <button type="button" class=${this.cls("button")} @click=${()=>this.#r()}>
            ${r("account.retry")}
          </button>
        </p>
      `:n`
      ${this.heading?n`<h2 class=${this.cls("heading")}>${this.heading}</h2>`:l}
      <div class="layout">
        <section class="panel" part="addresses">
          <h2 class="section-title ${this.cls("heading")}">${r("checkout.addresses")}</h2>
          ${this._account.registered?this.#h():this.#g()}
        </section>
        <section part="cart">
          <h2 class="section-title ${this.cls("heading")}">${r("checkout.cart")}</h2>
          <shop-cart mode="checkout" continue-url="/"></shop-cart>
          ${this.#f()}
        </section>
      </div>
    `}#h(){let e=this._account.billing;return n`
      <div class="block">
        <p class="block-title">${r("checkout.billing")}</p>
        <div class="address">
          <p>${e.name}</p>
          <p>${e.street}</p>
          <p>${C(e)}</p>
        </div>
      </div>

      <div class="block">
        <p class="block-title">${r("checkout.delivery")}</p>
        ${this._mode==="new"?this.#m():this.#p()}
      </div>
    `}#p(){let e=this.#a(),t=this._account.defaultShipping?this._account.defaultShipping.id:"",i=this._account.addresses.filter(a=>a.id!==t);return n`
      <div class="address">
        ${e?n`
              <p>${e.name}</p>
              <p>${e.street}</p>
              <p>${C(e)}</p>
            `:n`<p>${r("checkout.deliveryIsBilling")}</p>`}
      </div>

      ${i.length?n`
            <div class="choose field">
              <label class=${this.cls("label")} for="saved">${r("checkout.saved")}</label>
              <select
                class=${this.cls("select")}
                id="saved"
                @change=${a=>this._selectedId=a.target.value}
              >
                <option value=${t} .selected=${this._selectedId===t}>
                  ${t?r("checkout.default"):r("checkout.deliveryIsBilling")}
                </option>
                ${k(i,a=>a.id,a=>n`
                    <option value=${a.id} .selected=${this._selectedId===a.id}>${a.name}</option>
                  `)}
              </select>
            </div>
          `:l}

      <div class="actions">
        <button
          type="button"
          class=${this.cls("buttonSecondary")}
          @click=${()=>{this._form=J(),this._mode="new"}}
        >
          ${r("checkout.newAddress")}
        </button>
      </div>
    `}#m(){return n`
      <p class="block-title">${r("checkout.newAddressTitle")}</p>

      ${this._account.addresses.length?n`
            <div class="choose field">
              <label class=${this.cls("label")} for="prefill">${r("checkout.prefill")}</label>
              <select
                class=${this.cls("select")}
                id="prefill"
                @change=${e=>this.#i(e.target.value)}
              >
                <option value=""></option>
                ${k(this._account.addresses,e=>e.id,e=>n`<option value=${e.id}>${e.name}</option>`)}
              </select>
            </div>
          `:l}

      <div class="fields choose">
        ${this.#c("new-name",r("register.name"),"name",{required:!0,autocomplete:"shipping name"})}
        ${this.#c("new-street",r("register.street"),"street",{required:!0,autocomplete:"shipping street-address"})}
        ${this.#c("new-city",r("register.city"),"city",{required:!0,autocomplete:"shipping address-level2"})}
        ${this.#c("new-postcode",r("register.postcode"),"zipcode",{required:!0,autocomplete:"shipping postal-code"})}
        ${this.#c("new-country",r("register.country"),"country",{required:!0,autocomplete:"shipping country-name"})}
        ${this.#c("new-email",r("register.email"),"email",{type:"email",autocomplete:"shipping email"})}
        ${this.#c("new-phone",r("register.phone"),"phone",{type:"tel",autocomplete:"shipping tel"})}
      </div>

      <div class="actions">
        <button
          type="button"
          class=${this.cls("buttonSecondary")}
          @click=${()=>{this._mode="saved",this._form=J()}}
        >
          ${r("checkout.cancel")}
        </button>
      </div>
    `}#c(e,t,i,a={}){return n`
      <div class="field" part="field">
        <label class=${this.cls("label")} part="label" for=${e}>
          ${t}${a.required?"*":""}
        </label>
        <input
          class=${this.cls("input")}
          part="input"
          id=${e}
          name=${e}
          type=${a.type||"text"}
          autocomplete=${a.autocomplete||"off"}
          .value=${this._form[i]}
          @input=${o=>this.#n(i,o.target.value)}
          ?required=${!!a.required}
        />
      </div>
    `}#g(){return n`
      <div class="account-switch">
        <input
          class=${this.cls("check")}
          type="checkbox"
          id="create-account"
          .checked=${this._createAccount}
          @change=${e=>this._createAccount=e.target.checked}
        />
        <label class=${this.cls("label")} for="create-account">${r("checkout.createAccount")}</label>
      </div>

      <shop-register
        id="guest"
        mode=${this._createAccount?"account":"guest"}
        login-url=${this.loginUrl}
        data-protection-url=${this.dataProtectionUrl}
        redirect-url=${`${window.location.pathname}#focus`}
      ></shop-register>
    `}#f(){return this._createAccount?n`<p class="note ${this.cls("muted")}">${r("checkout.createAccountHint")}</p>`:n`
      <div class="buy">
        ${this._error?n`
              <div class="shop-message ${this.cls("alertError")}" role="alert">
                ${this._error}
                ${this._accountExists?n`
                      <a class=${this.cls("link")} href=${this.loginUrl}>
                        ${r("register.loginLink")}
                      </a>
                    `:l}
              </div>
            `:l}
        ${this._count===0?n`<p>${r("checkout.emptyCart")}</p>`:n`
              <div class="actions">
                <button
                  type="button"
                  class=${this.cls("buttonPrimary")}
                  ?disabled=${this._busy||this._count===null}
                  @click=${()=>this.#o()}
                >
                  ${this._busy?r("checkout.buying"):r("checkout.buy")}
                </button>
                <a class="paypal" href=${this.#u()} aria-label=${r("cart.paypal")}>
                  <img src=${this.paypalImage} alt=${r("cart.paypal")} />
                </a>
              </div>
              <p class="note ${this.cls("muted")}">${r("checkout.buyNote")}</p>
            `}
      </div>
    `}};customElements.define("shop-checkout",it);var rt=class extends h{static properties={apiUrl:{type:String,attribute:"api-url"},ordersUrl:{type:String,attribute:"orders-url"},continueUrl:{type:String,attribute:"continue-url"},heading:{type:String},_state:{state:!0},_error:{state:!0},_summary:{state:!0},_mailFailed:{state:!0}};static styles=[h.baseStyles,m`
      .block {
        margin-top: 2rem;
      }
      .block-title {
        font-weight: 600;
        margin-bottom: 0.5rem;
      }
      .lead {
        font-size: 1.125rem;
      }
      dl.bank {
        display: grid;
        grid-template-columns: minmax(0, 12rem) minmax(0, 1fr);
        gap: 0.35rem 1rem;
        margin: 0;
      }
      dl.bank dt {
        font-weight: 400;
      }
      dl.bank dd {
        margin: 0;
        font-weight: 600;
      }
      @media (max-width: 32rem) {
        dl.bank {
          grid-template-columns: minmax(0, 1fr);
        }
        dl.bank dd {
          margin-bottom: 0.5rem;
        }
      }
      .address p {
        margin: 0.25rem 0;
        font-weight: 600;
      }
      form {
        margin-top: 1rem;
      }
    `];constructor(){super(),this.apiUrl=qe,this.ordersUrl="/bestellungen/",this.continueUrl="/",this.heading="",this._state="loading",this._error="",this._summary=null,this._mailFailed=!1}connectedCallback(){super.connectedCallback(),this.#e()}async#e(){let e=cs(window.location.search);if(this._mailFailed=new URLSearchParams(window.location.search).get("mail")==="error",!e){this._state="missing";return}this._state="loading",this._error="";try{let t=await u("getInvoiceSummary",{ar_link:e});this._summary=t,this._state="ready",ts(t)}catch(t){this._error=r(t.code||"SHOP_API_ERROR"),this._state="error"}}render(){if(this._state==="loading")return n`<p class=${this.cls("muted")} role="status">${r("invoice.loading")}</p>`;if(this._state==="missing")return n`
        <p>${r("invoice.noLink")}</p>
        <p><a class=${this.cls("link")} href=${this.ordersUrl}>${r("invoice.toOrders")}</a></p>
      `;if(this._state==="error")return n`
        <div class=${this.cls("alertError")} role="alert">${this._error}</div>
        <p class="actions">
          <button type="button" class=${this.cls("button")} @click=${()=>this.#e()}>
            ${r("account.retry")}
          </button>
        </p>
      `;let e=this._summary;return n`
      ${this.heading?n`<h2 class=${this.cls("heading")}>${this.heading}</h2>`:l}
      ${this._mailFailed?n`<div class=${this.cls("alertError")} role="alert">${r("invoice.mailFailed")}</div>`:n`<p class="lead">${r("invoice.mailTo")} ${e.email}</p>`}
      <p>${r("invoice.downloadHint")}</p>

      <form action=${`${this.apiUrl}?action=downloadInvoiceLink`} method="post" part="download">
        <input type="hidden" name="ar-link" value=${e.ar_link||""} />
        <input type="hidden" name="content-type" value="application/pdf" />
        <button class=${this.cls("button")} type="submit">${r("invoice.download")}</button>
      </form>

      ${this.#s(e)} ${this.#a(e)}
    `}#s(e){return e.paid?l:e.pending?this.#t():this.#r(e)}#t(){return n`
      <div class="block" part="pending">
        <div class=${this.cls("alertInfo")} role="status">
          <p class="block-title">${r("invoice.pendingTitle")}</p>
          <p>${r("invoice.pendingHint")}</p>
        </div>
      </div>
    `}#r(e){return n`
      <div class="block" part="bank">
        <p class="block-title">${r("invoice.transferHint")}</p>
        <dl class="bank">
          <dt>${r("invoice.bank")}</dt>
          <dd>${e.payment_term_bank}</dd>
          <dt>${r("invoice.iban")}</dt>
          <dd>${e.payment_term_iban}</dd>
          <dt>${r("invoice.bic")}</dt>
          <dd>${e.payment_term_bic}</dd>
          <dt>${r("invoice.purpose")}</dt>
          <dd>${e.payment_term_purpose}</dd>
          <dt>${r("invoice.owner")}</dt>
          <dd>${e.payment_term_account_owner}</dd>
          <dt>${r("invoice.amount")}</dt>
          <dd>
            ${E(e.payment_term_amount)} ${e.payment_term_currency||""}
          </dd>
        </dl>
      </div>
    `}#a(e){let t=e.shipping||{};return n`
      <div class="block" part="shipping">
        <p class="block-title">${r("invoice.deliveryHint")}</p>
        <div class="address">
          <p>${t.name||""}</p>
          <p>${t.street||""}</p>
          <p>${C({zipcode:t.zipcode||"",city:t.city||"",country:t.country||""})}</p>
        </div>
      </div>
      <p class="block">
        <a class=${this.cls("link")} href=${this.continueUrl}>${r("cart.continue")}</a>
      </p>
    `}};customElements.define("shop-invoice",rt);var at=class extends h{static properties={loginUrl:{type:String,attribute:"login-url"},logoutUrl:{type:String,attribute:"logout-url"},registerUrl:{type:String,attribute:"register-url"},accountUrl:{type:String,attribute:"account-url"},cartUrl:{type:String,attribute:"cart-url"},loginLabel:{type:String,attribute:"login-label"},logoutLabel:{type:String,attribute:"logout-label"},registerLabel:{type:String,attribute:"register-label"},accountLabel:{type:String,attribute:"account-label"},cartLabel:{type:String,attribute:"cart-label"},_account:{state:!0},_count:{state:!0},_busy:{state:!0},_hint:{state:!0}};static styles=[h.baseStyles,m`
      ul {
        list-style: none;
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin: 0;
        padding: 0;
      }
      li {
        position: relative;
      }
      a,
      button {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        white-space: nowrap;
      }
      svg {
        flex: none;
      }
      /* Das Theme setzt die Beschriftung kursiv (fst-italic). */
      .label {
        font-style: italic;
      }
      /* Ersetzt das Bootstrap-Popover des alten Knopfes — der brauchte
         bootstrap.bundle.js, das ein fremdes Theme nicht geladen haben muss. */
      .hint {
        position: absolute;
        top: calc(100% + 0.35rem);
        right: 0;
        z-index: 20;
        padding: 0.5rem 0.75rem;
        border-radius: var(--shop-radius, 0.375rem);
        border: 1px solid var(--shop-border-color, #dee2e6);
        background: var(--shop-surface, #fff);
        color: var(--shop-danger, #dc3545);
        font-weight: 600;
        white-space: nowrap;
      }
    `];#e=[];#s=null;constructor(){super(),this.loginUrl="/login/",this.logoutUrl="/logout/",this.registerUrl="/registrieren/",this.accountUrl="/pers\xF6nliche-daten/",this.cartUrl="/warenkorb/",this.loginLabel="",this.logoutLabel="",this.registerLabel="",this.accountLabel="",this.cartLabel="",this._account=!1,this._count=0,this._busy=!1,this._hint=!1}connectedCallback(){super.connectedCallback(),this.#e.push(S(P,e=>{let t=e.detail||{};typeof t.account=="boolean"?this._account=t.account:this.#t(D())}),S(R,e=>{let t=e.detail||{};typeof t.count=="number"&&(this._count=t.count)})),this.#t(U())}disconnectedCallback(){super.disconnectedCallback();for(let e of this.#e)e();this.#e=[],clearTimeout(this.#s)}async#t(e){try{let t=await e;if(!t)return;this._account=!!t.account,this._count=Number(t.cart_pos_count)||0}catch{}}async#r(){if(!this._busy){this._busy=!0;try{await Lt(),x(P,{account:!1}),window.location.href=this.logoutUrl}catch(e){this._busy=!1,this.#a(r(e.code||"SHOP_API_ERROR"))}}}#a(e){clearTimeout(this.#s),this._hint=e,this.#s=setTimeout(()=>{this._hint=!1},2500)}render(){return n`
      <ul part="buttons">
        <li>${this._account?this.#n():this.#i(this.loginUrl,this.loginLabel||r("header.login"),this.#o(),"buttonSecondary")}</li>
        <li>
          ${this._account?this.#i(this.accountUrl,this.accountLabel||r("header.account"),this.#u(),"buttonSecondary"):this.#i(this.registerUrl,this.registerLabel||r("header.register"),this.#u(),"buttonSecondary")}
        </li>
        <li>${this.#l()}</li>
      </ul>
    `}#i(e,t,i,a){return n`
      <a class=${this.cls(a)} part="button" href=${e}>
        ${i}<span class="label">${t}</span>
      </a>
    `}#n(){return n`
      <button
        type="button"
        class=${this.cls("buttonSecondary")}
        part="button"
        ?disabled=${this._busy}
        @click=${()=>this.#r()}
      >
        ${this.#d()}<span class="label">${this.logoutLabel||r("header.logout")}</span>
      </button>
      ${this._hint?n`<span class="hint" role="alert">${this._hint}</span>`:l}
    `}#l(){let e=this.cartLabel||r("header.cart");return this._count>0?n`
        <a class=${this.cls("buttonPrimary")} part="button" href=${this.cartUrl}>
          ${this.#h()}<span class="label">${e} (${this._count})</span>
        </a>
      `:n`
      <button
        type="button"
        class=${this.cls("buttonSecondary")}
        part="button"
        @click=${()=>this.#a(r("header.cartEmpty"))}
      >
        ${this.#p()}<span class="label">${e} (0)</span>
      </button>
      ${this._hint?n`<span class="hint" role="alert">${this._hint}</span>`:l}
    `}#o(){return n`
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
        <path d="M11 1a2 2 0 0 0-2 2v4a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h5V3a3 3 0 0 1 6 0v4a.5.5 0 0 1-1 0V3a2 2 0 0 0-2-2M3 8a1 1 0 0 0-1 1v5a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V9a1 1 0 0 0-1-1z"/>
      </svg>
    `}#d(){return n`
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
        <path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2m3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2M5 8h6a1 1 0 0 1 1 1v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1"/>
      </svg>
    `}#u(){return n`
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
        <path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6m2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0m4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4m-1-.004c-.001-.246-.154-.986-.832-1.664C11.516 10.68 10.289 10 8 10s-3.516.68-4.168 1.332c-.678.678-.83 1.418-.832 1.664z"/>
      </svg>
    `}#h(){return n`
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
        <path d="M11.354 6.354a.5.5 0 0 0-.708-.708L8 8.293 6.854 7.146a.5.5 0 1 0-.708.708l1.5 1.5a.5.5 0 0 0 .708 0z"/>
        <path d="M.5 1a.5.5 0 0 0 0 1h1.11l.401 1.607 1.498 7.985A.5.5 0 0 0 4 12h1a2 2 0 1 0 0 4 2 2 0 0 0 0-4h7a2 2 0 1 0 0 4 2 2 0 0 0 0-4h1a.5.5 0 0 0 .491-.408l1.5-8A.5.5 0 0 0 14.5 3H2.89l-.405-1.621A.5.5 0 0 0 2 1zm3.915 10L3.102 4h10.796l-1.313 7zM6 14a1 1 0 1 1-2 0 1 1 0 0 1 2 0m7 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0"/>
      </svg>
    `}#p(){return n`
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
        <path d="M0 1.5A.5.5 0 0 1 .5 1H2a.5.5 0 0 1 .485.379L2.89 3H14.5a.5.5 0 0 1 .491.592l-1.5 8A.5.5 0 0 1 13 12H4a.5.5 0 0 1-.491-.408L2.01 3.607 1.61 2H.5a.5.5 0 0 1-.5-.5M3.102 4l1.313 7h8.17l1.313-7zM5 12a2 2 0 1 0 0 4 2 2 0 0 0 0-4m7 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4m-7 1a1 1 0 1 1 0 2 1 1 0 0 1 0-2m7 0a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/>
      </svg>
    `}};customElements.define("shop-account-buttons",at);function le(s){let e=String(s||"");if(!e)return"";try{let t=new URL(e,window.location.origin);return t.origin===window.location.origin?t.href:t.pathname+t.search+t.hash}catch{return e}}function Ce(s){let e=new URLSearchParams(s||"").get("terms");return e?e.trim():""}var nt=class extends h{static properties={resultsUrl:{type:String,attribute:"results-url"},placeholder:{type:String},buttonLabel:{type:String,attribute:"button-label"},info:{type:String},minLength:{type:Number,attribute:"min-length"},autoFocus:{type:String,attribute:"auto-focus"},_items:{state:!0},_open:{state:!0},_active:{state:!0},_message:{state:!0}};static styles=[h.baseStyles,m`
      .info {
        display: block;
        margin-bottom: 0.5rem;
      }
      .group {
        position: relative;
        display: flex;
      }
      .group input {
        flex: 1 1 auto;
        min-width: 0;
      }
      .icon {
        display: flex;
        align-items: center;
        padding: 0 0.75rem;
        border: 1px solid var(--shop-border-color, #dee2e6);
        border-right: 0;
        border-radius: var(--shop-radius, 0.375rem) 0 0 var(--shop-radius, 0.375rem);
        background: var(--shop-muted-surface, #f8f9fa);
      }
      .group input {
        border-radius: 0;
      }
      .group button {
        border-radius: 0 var(--shop-radius, 0.375rem) var(--shop-radius, 0.375rem) 0;
      }
      /* Entspricht .search-autocomplete-items aus theme.css — die Regel gilt
         im ShadowRoot nicht mehr, also steht sie hier. */
      ul.list {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 99;
        max-height: 360px;
        overflow-y: auto;
        margin: 0;
        padding: 0;
        list-style: none;
        background: var(--shop-surface, #fff);
        border: 1px solid var(--shop-border-color, #d4d4d4);
        border-top: none;
      }
      li[role='option'] {
        padding: 8px;
        cursor: pointer;
      }
      li[role='option']:hover,
      li[aria-selected='true'] {
        background-color: var(--shop-hover-surface, #e9e9e9);
      }
      li.plain {
        padding: 8px;
      }
      .more {
        font-weight: 600;
      }
    `];#e=null;#s=null;#t=0;constructor(){super(),this.resultsUrl="/suchergebnisse/",this.placeholder="",this.buttonLabel="",this.info="",this.minLength=1,this.autoFocus="false",this._items=[],this._open=!1,this._active=-1,this._message=""}connectedCallback(){super.connectedCallback(),this.#s=e=>{e.composedPath().includes(this)||this.#i()},document.addEventListener("click",this.#s)}disconnectedCallback(){super.disconnectedCallback(),document.removeEventListener("click",this.#s),clearTimeout(this.#e)}firstUpdated(){let e=Ce(window.location.search),t=this.$("search-input");e&&t&&(t.value=e),this.autoFocus==="true"&&t&&t.focus()}#r(e){clearTimeout(this.#e),this.#e=setTimeout(()=>this.#a(e),250)}async#a(e){let t=++this.#t;try{let i=await u("fastSearch",{terms:e});if(t!==this.#t)return;this._items=Array.isArray(i)?i:[],this._message="",this._active=-1,this._open=!0}catch(i){if(t!==this.#t)return;this._items=[],this._message=r(i.code||"SHOP_API_ERROR"),this._open=!0}}#i(){this._open=!1,this._active=-1}get#n(){let e=this.$("search-input");return e?e.value.trim():""}#l(e){return`${this.resultsUrl}?terms=${encodeURIComponent(e)}#focus`}#o(e){let t=e.target.value.trim();if(this._message="",t.length<Math.max(1,this.minLength)){clearTimeout(this.#e),this.#t++,this._items=[],this.#i();return}this.#r(t)}#d(e){let t=this._items.length+(this._items.length?1:0);if(e.key==="ArrowDown"||e.key==="ArrowUp"){if(!this._open||!t)return;e.preventDefault();let i=e.key==="ArrowDown"?1:-1;this._active<0?this._active=i>0?0:t-1:this._active=(this._active+i+t)%t;return}if(e.key==="Escape"){this.#i();return}e.key==="Enter"&&(e.preventDefault(),this.#u())}#u(){if(this._open&&this._active>-1){if(this._active<this._items.length){this.#h(le(this._items[this._active].hyperlink));return}this.#p();return}this.#p()}#h(e){e&&(window.location.href=e)}#p(){let e=this.#n;if(!e){let t=this.$("search-input");t&&(t.placeholder=r("search.enterTerm"));return}window.location.href=this.#l(e)}render(){let e=this._open;return n`
      ${this.info?n`<span class="info ${this.cls("muted")}">${this.info}</span>`:l}
      <div class="group">
        <span class="icon" aria-hidden="true">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
            <path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0"/>
          </svg>
        </span>
        <input
          id="search-input"
          class=${this.cls("input")}
          part="input"
          type="search"
          role="combobox"
          aria-expanded=${e?"true":"false"}
          aria-controls="search-list"
          aria-autocomplete="list"
          aria-activedescendant=${e&&this._active>-1?`option-${this._active}`:""}
          aria-label=${r("search.label")}
          placeholder=${this.placeholder||r("search.placeholder")}
          autocomplete="off"
          @input=${this.#o}
          @keydown=${this.#d}
        >
        <button
          type="button"
          class=${this.cls("buttonSecondary")}
          part="button"
          @click=${()=>this.#p()}
        >${this.buttonLabel||r("search.submit")}</button>
        ${e?this.#m():l}
      </div>
    `}#m(){if(this._message||!this._items.length)return n`<ul class="list" id="search-list" role="listbox" aria-label=${r("search.label")}>
        <li class="plain ${this.cls("muted")}">${this._message||r("search.none")}</li>
      </ul>`;let e=this._items.length;return n`
      <ul class="list" id="search-list" role="listbox" aria-label=${r("search.label")}>
        ${k(this._items,(t,i)=>`${t.partnumber||""}-${i}`,(t,i)=>n`
            <li
              id="option-${i}"
              role="option"
              aria-selected=${this._active===i?"true":"false"}
              @click=${()=>this.#h(le(t.hyperlink))}
            >${this.#c(String(t.description||""))}</li>
          `)}
        <li
          id="option-${e}"
          class="more"
          role="option"
          aria-selected=${this._active===e?"true":"false"}
          @click=${()=>this.#p()}
        >${r("search.more")}</li>
      </ul>
    `}#c(e){let t=this.#n;if(!t)return e;let i=e.toLowerCase().indexOf(t.toLowerCase());return i<0?e:n`${e.slice(0,i)}<strong>${e.slice(i,i+t.length)}</strong>${e.slice(i+t.length)}`}};customElements.define("shop-search",nt);var ot=class extends h{static properties={terms:{type:String},heading:{type:String},_items:{state:!0},_state:{state:!0},_error:{state:!0},_more:{state:!0},_busy:{state:!0}};static styles=[h.baseStyles,m`
      .count {
        margin: 0 0 1.5rem;
      }
      ul {
        list-style: none;
        margin: 0;
        padding: 0;
      }
      li {
        margin-bottom: 0.75rem;
      }
      a.hit {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 2fr);
        gap: 1.5rem;
        align-items: center;
        padding: 1rem 1.5rem;
        border: 1px solid var(--shop-border-color, #dee2e6);
        border-radius: var(--shop-radius, 0.375rem);
        color: inherit;
        text-decoration: none;
      }
      a.hit:hover {
        background: var(--shop-hover-surface, #f8f9fa);
      }
      @media (max-width: 40rem) {
        a.hit {
          grid-template-columns: minmax(0, 1fr);
        }
      }
      img {
        max-width: 100%;
        height: auto;
      }
      .title {
        margin: 0 0 0.5rem;
        font-size: 1.25rem;
      }
      .trail {
        margin: 0;
      }
      .more {
        text-align: center;
        margin-top: 1.5rem;
      }
    `];constructor(){super(),this.terms="",this.heading="",this._items=[],this._state="loading",this._error="",this._more=!1,this._busy=!1}connectedCallback(){super.connectedCallback(),this.terms||(this.terms=Ce(window.location.search)),this.#e()}async#e(){if(!this.terms){this._state="empty";return}this._state="loading",this._error="";try{let e=await u("moreSearchResults",{terms:this.terms}),t=Array.isArray(e)?e:[];this._items=t,this._more=t.length>10,this._state=t.length?"ready":"empty"}catch(e){this._error=r(e.code||"SHOP_API_ERROR"),this._state="error"}}async#s(){if(!this._busy){this._busy=!0;try{let e=await u("fullSearch",{terms:this.terms}),t=Array.isArray(e)?e:[];this._items=[...this._items,...t],this._more=!1}catch(e){this._error=r(e.code||"SHOP_API_ERROR")}finally{this._busy=!1}}}render(){return this._state==="loading"?n`<p class=${this.cls("muted")} role="status">${r("results.loading")}</p>`:this._state==="error"?n`
        <div class=${this.cls("alertError")} role="alert">${this._error}</div>
        <p class="actions">
          <button type="button" class=${this.cls("button")} @click=${()=>this.#e()}>
            ${r("account.retry")}
          </button>
        </p>
      `:this._state==="empty"?n`<div class=${this.cls("alertInfo")} role="status">${r("search.none")}</div>`:n`
      ${this.heading?n`<h2 class=${this.cls("heading")}>${this.heading}</h2>`:l}
      <p class="count ${this.cls("muted")}">${r("results.found")}: ${this._items.length}</p>
      ${this._error?n`<div class=${this.cls("alertError")} role="alert">${this._error}</div>`:l}
      <ul part="results">
        ${k(this._items,(e,t)=>`${e.partnumber||""}-${t}`,e=>this.#t(e))}
      </ul>
      ${this._more?n`
            <div class="more">
              <button
                type="button"
                class=${this.cls("buttonPrimary")}
                ?disabled=${this._busy}
                @click=${()=>this.#s()}
              >${this._busy?r("results.loading"):r("search.more")}</button>
            </div>
          `:l}
    `}#t(e){let t=String(e.description||""),i=[e.partnumber,...e.breadcrumbs||[]].filter(Boolean).join(" | ");return n`
      <li>
        <a class="hit" part="hit" href=${le(e.hyperlink)}>
          <span>
            ${e.image?n`<img src=${e.image} alt=${t} loading="lazy">`:l}
          </span>
          <span>
            <h3 class="title">${t}</h3>
            <p class="trail ${this.cls("muted")}">${i}</p>
          </span>
        </a>
      </li>
    `}};customElements.define("shop-search-results",ot);var Ls=/\S+@\S+\.\S+/,lt=class extends h{static properties={redirectUrl:{type:String,attribute:"redirect-url"},heading:{type:String},_salutations:{state:!0},_values:{state:!0},_state:{state:!0},_error:{state:!0},_busy:{state:!0}};static styles=[h.baseStyles,m`
      textarea {
        width: 100%;
        font: inherit;
      }
      .shop-message {
        margin-top: 1.5rem;
        max-width: var(--shop-form-width, 32rem);
      }
    `];get#e(){return[{id:"company-name",label:r("contact.companyName"),autocomplete:"organization"},{id:"salutation",label:r("contact.salutation"),select:!0},{id:"name",label:r("contact.name"),autocomplete:"name",required:!0},{id:"email",label:r("contact.email"),autocomplete:"email",type:"email",required:!0},{id:"phone",label:r("contact.phone"),autocomplete:"tel",type:"tel"},{id:"issue",label:r("contact.issue"),textarea:!0,required:!0}]}constructor(){super(),this.redirectUrl="/kontakt-danke/",this.heading="",this._salutations=[],this._values={"company-name":"",salutation:"",name:"",email:"",phone:"",issue:""},this._state="loading",this._error="",this._busy=!1}#s=!1;async connectedCallback(){if(await super.connectedCallback(),this.#s)return;this.#s=!0;let t=new URLSearchParams(window.location.search).get("pid"),i=t?`${r("contact.productRequest")} ${t}:
`:"";try{let a=await u("contactInit",{lang:document.documentElement.lang});this._salutations=a&&a.salutations||[],this._values={"company-name":a&&a.company_name||"",salutation:a&&a.salutation||"",name:a&&a.name||"",email:a&&a.email||"",phone:a&&a.phone||"",issue:i}}catch(a){this._values={...this._values,issue:i},this._error=r(a.code||"SHOP_API_ERROR")}finally{this._state="ready",await this.updateComplete;let a=this.$(this._values.name?"issue":"name");a&&a.focus()}}#t(e,t){this._values={...this._values,[e]:t}}#r(){for(let e of this.#e)if(e.required&&!String(this._values[e.id]||"").trim())return e.label;return""}#a(){let e=[r("contact.subject")];for(let t of this.#e)e.push(`${t.label}: ${this._values[t.id]||""}`);return e.join(`
`)+`
`}async#i(e){if(e.preventDefault(),this._busy)return;this._error="";let t=this.#r();if(t){this._error=r("contact.required")+t+r("contact.missing");return}if(!Ls.test(this._values.email.trim())){this._error=r("contact.emailInvalid");let i=this.$("email");i&&i.focus();return}this._busy=!0;try{let i=await u("sendContactMail",{email:this._values.email.trim(),term:this.#a()});if(!(i&&(i.success===!0||i.success==="true"))){this._busy=!1,this._error=r("contact.sendFailed");return}window.location.href=this.redirectUrl}catch{this._busy=!1,this._error=r("contact.sendFailed")}}render(){return this._state==="loading"?n`<p class=${this.cls("muted")} role="status">${r("contact.loading")}</p>`:n`
      ${this.heading?n`<h2 class=${this.cls("heading")}>${this.heading}</h2>`:l}
      <form class=${this.cls("form")} @submit=${this.#i} novalidate>
        <div class="fields">${this.#e.map(e=>this.#n(e))}</div>
        ${this._error?n`<div class="shop-message ${this.cls("alertError")}" role="alert">${this._error}</div>`:l}
        <div class="actions">
          <button type="submit" class=${this.cls("buttonSecondary")} ?disabled=${this._busy}>
            ${this._busy?r("contact.sending"):r("contact.submit")}
          </button>
        </div>
      </form>
    `}#n(e){let t=this._values[e.id]||"",i=n`
      <label class=${this.cls("label")} part="label" for=${e.id}>
        ${e.label}${e.required?"*":""}
      </label>
    `;return e.select?n`
        <div class="field" part="field">
          ${i}
          <select
            class=${this.cls("select")}
            part="select"
            id=${e.id}
            name=${e.id}
            @change=${a=>this.#t(e.id,a.target.value)}
          >
            <option value="" .selected=${!t}></option>
            ${this._salutations.map(a=>n`
                <option value=${a.translation} .selected=${t===a.translation}>
                  ${a.translation}
                </option>
              `)}
          </select>
        </div>
      `:e.textarea?n`
        <div class="field" part="field">
          ${i}
          <textarea
            class=${this.cls("input")}
            part="input"
            id=${e.id}
            name=${e.id}
            rows="5"
            .value=${t}
            @input=${a=>this.#t(e.id,a.target.value)}
          ></textarea>
        </div>
      `:n`
      <div class="field" part="field">
        ${i}
        <input
          class=${this.cls("input")}
          part="input"
          id=${e.id}
          name=${e.id}
          type=${e.type||"text"}
          autocomplete=${e.autocomplete||"off"}
          .value=${t}
          @input=${a=>this.#t(e.id,a.target.value)}
        >
      </div>
    `}};customElements.define("shop-contact",lt);var ct=null;function ds(s){let e=document.getElementById("cart-count"),t=document.getElementById("cart-button"),i=document.getElementById("empty-cart-button");!e&&!t&&!i||(e&&(e.textContent=String(s)),t&&(t.style.display=s>0?"block":"none"),i&&(i.style.display=s>0?"none":"block"))}S(R,s=>{let e=s.detail&&s.detail.count;typeof e=="number"&&(ct=e,ds(e))});window.addEventListener("load",()=>{ct!==null&&setTimeout(()=>ds(ct),0)});S(P,()=>{D().catch(()=>{})});window.ShopUI=Object.freeze({version:"0.9.0",elements:["shop-login","shop-register","shop-cart","shop-add-to-cart","shop-account-nav","shop-account-overview","shop-account-profile","shop-account-payment","shop-account-orders","shop-account-addresses","shop-checkout","shop-invoice","shop-account-buttons","shop-search","shop-search-results","shop-contact"]});export{I as ApiError,ne as SHOP_ACCOUNT_LOADED,P as SHOP_AUTH_CHANGED,R as SHOP_CART_CHANGED,Rs as SHOP_ERROR,u as apiRequest,x as emit,U as getContext,S as on,D as refreshContext};
/*! Bundled license information:

@lit/reactive-element/css-tag.js:
  (**
   * @license
   * Copyright 2019 Google LLC
   * SPDX-License-Identifier: BSD-3-Clause
   *)

@lit/reactive-element/reactive-element.js:
  (**
   * @license
   * Copyright 2017 Google LLC
   * SPDX-License-Identifier: BSD-3-Clause
   *)

lit-html/lit-html.js:
  (**
   * @license
   * Copyright 2017 Google LLC
   * SPDX-License-Identifier: BSD-3-Clause
   *)

lit-element/lit-element.js:
  (**
   * @license
   * Copyright 2017 Google LLC
   * SPDX-License-Identifier: BSD-3-Clause
   *)

lit-html/is-server.js:
  (**
   * @license
   * Copyright 2022 Google LLC
   * SPDX-License-Identifier: BSD-3-Clause
   *)

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
