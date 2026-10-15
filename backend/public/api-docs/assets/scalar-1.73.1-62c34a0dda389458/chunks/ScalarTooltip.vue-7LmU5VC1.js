/*!
*    _____ _________    __    ___    ____
*   / ___// ____/   |  / /   /   |  / __ \
*   \__ \/ /   / /| | / /   / /| | / /_/ /
*  ___/ / /___/ ___ |/ /___/ ___ |/ _, _/
* /____/\____/_/  |_/_____/_/  |_/_/ |_|
*
* @scalar/api-reference 1.73.1
*
* Website: https://scalar.com
* GitHub:  https://github.com/scalar/scalar
* License: https://github.com/scalar/scalar/blob/main/LICENSE
**/
import{Cn as e,Hn as t,_t as n,pr as r}from"./workspace-events-D26dzCw3.js";import{En as i}from"./slugify-CNAAe-SV.js";import{ut as a,v as o}from"./reactivity.esm-bundler-ChxH1Bzf.js";import{H as s,K as c,S as l,l as u,p as d}from"./runtime-core.esm-bundler-BO50tpfZ.js";var f=({server:n,path:i,urlParams:a})=>{let o=e(n);return r(t(n?.url??``,o),i,a)},p=(e,t=``)=>{let n=[];return e.forEach(e=>{if(e.type===`apiKey`){let r=e[`x-scalar-secret-token`]||t;if(e.in===`header`)return n.push({in:e.in,name:e.name,value:r});if(e.in===`query`)return n.push({in:`query`,name:e.name,value:r});if(e.in===`cookie`)return n.push({in:`cookie`,name:e.name,value:r})}if(e.type===`http`){if(e.scheme===`basic`){let t=e[`x-scalar-secret-username`]||``,r=e[`x-scalar-secret-password`]||``;return t===``&&r===``?null:n.push({in:`header`,name:`Authorization`,value:`${t}:${r}`,format:`basic`})}let r=e[`x-scalar-secret-token`];return n.push({in:`header`,name:`Authorization`,value:r||t,format:`bearer`})}if(e.type===`oauth2`){let r=Object.values(e?.flows??{}).filter(i).find(e=>e[`x-scalar-secret-token`])?.[`x-scalar-secret-token`]??``;return n.push({in:`header`,name:`Authorization`,value:r||t,format:`bearer`})}if(e.type===`openIdConnect`){let r=Object.values(e?.flows??{}).filter(i).find(e=>e[`x-scalar-secret-token`])?.[`x-scalar-secret-token`]??``;return n.push({in:`header`,name:`Authorization`,value:r||t,format:`bearer`})}return null}),n},m=l({__name:`ScalarTooltip`,props:{content:{default:``},delay:{default:()=>300},placement:{default:`top`},offset:{default:()=>4}},setup(e){let t=o(null);return n({content:u(()=>e.content),delay:u(()=>e.delay),placement:u(()=>e.placement),offset:u(()=>e.offset),targetRef:u(()=>t.value?.children?.[0]||t.value||void 0)}),(e,n)=>(s(),d(`div`,{ref_key:`wrapperRef`,ref:t,class:a({contents:!!e.$slots.default})},[c(e.$slots,`default`)],2))}});export{p as n,f as r,m as t};
//# sourceMappingURL=ScalarTooltip.vue-7LmU5VC1.js.map