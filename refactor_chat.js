const fs = require('fs');

const path = 'c:/Users/Leslie/Local Sites/mhbai/app/public/wp-content/plugins/modern-hotel-booking/assets/js/mhbo-chat-widget.js';
let code = fs.readFileSync(path, 'utf8');

// 1. Add this.doc and this.win to constructor
code = code.replace(
    /this\.container = container;/,
    "this.container = container;\n\t\t\tthis.doc = container.ownerDocument || document;\n\t\t\tthis.win = this.doc.defaultView || window;"
);

// 2. Replace global sessionStorage with this.win.sessionStorage inside ChatWidget
// Note: we'll just replace all sessionStorage with this.win.sessionStorage.
// For the boot function, `this` is not bound, so we should change sessionStorage to `window.sessionStorage` there, 
// but wait, `boot` runs outside. We will replace sessionStorage with this.win.sessionStorage ONLY inside the class.
const classStart = code.indexOf('class ChatWidget');
const classEnd = code.indexOf('function boot()');

let classCode = code.substring(classStart, classEnd);
let bootCode = code.substring(classEnd);

// Replace sessionStorage
classCode = classCode.replace(/sessionStorage/g, 'this.win.sessionStorage');

// Replace document.createElement
classCode = classCode.replace(/document\.createElement/g, 'this.doc.createElement');

// Replace document.addEventListener
classCode = classCode.replace(/document\.addEventListener/g, 'this.doc.addEventListener');

// Replace document.querySelector(
classCode = classCode.replace(/document\.querySelector\(/g, 'this.doc.querySelector(');

// Replace document.dispatchEvent
classCode = classCode.replace(/document\.dispatchEvent/g, 'this.doc.dispatchEvent');

// Replace document.documentElement.lang
classCode = classCode.replace(/document\.documentElement\.lang/g, 'this.doc.documentElement.lang');

// Replace window.matchMedia
classCode = classCode.replace(/window\.matchMedia/g, 'this.win.matchMedia');

// Replace window.MhboVoiceInput
classCode = classCode.replace(/window\.MhboVoiceInput/g, 'this.win.MhboVoiceInput');

// Replace window.mhboChat
classCode = classCode.replace(/window\.mhboChat/g, 'this.win.mhboChat');

// Replace window.MhboModal
classCode = classCode.replace(/window\.MhboModal/g, 'this.win.MhboModal');

// Replace window.location
classCode = classCode.replace(/window\.location/g, 'this.win.location');

// Replace window.open
classCode = classCode.replace(/window\.open/g, 'this.win.open');

fs.writeFileSync(path, code.substring(0, classStart) + classCode + bootCode);
console.log('mhbo-chat-widget.js refactored for iframe compatibility.');
