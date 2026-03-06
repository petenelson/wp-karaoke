---
paths:
  - "*.js"
---

# JavaScript Coding Rules

* All JavaScript must follow [WordPress JavaScript Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/javascript/).
* Use `const` and `let` instead of `var`.
* JavaScript root namespace: `WPKQ` (WordPress Karaoke Queue).
* JavaScript source lives in `assets/js/`, with blocks JS in each block's folder in `includes/blocks/{block-name}/`.
* Refer to [10up's JavaScript Engineering Best Practices](https://10up.github.io/Engineering-Best-Practices/javascript/) for any other JavaScript-related decisions.
* Use arrow functions for anonymous functions, and named functions for any function that is reused or has significant logic.
* Only use closures when necessary; prefer module pattern or classes for encapsulation. Example:
```js
const MyModule = {
  buttonClicked: ( e ) => {
    // function code
    e.preventDefault();
    // Perform action.
  },

  init: () => {
    // initialization code
    const button = document.getElementById( 'my-button' );
    button.addEventListener( 'click', MyModule.buttonClicked );
  },
};

window.addEventListener( 'DOMContentLoaded', () => {
  MyModule.init();
} );
```
* Do not use jQuery.
