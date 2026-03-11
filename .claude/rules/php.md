---
paths:
  - "*.php"
---

# PHP Coding Rules

* All PHP must follow [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).
* Use short array syntax `[]` instead of `array()`.
* PHP root namespace: `WPKQ` (WordPress Karaoke Queue).
* PHP lives in `includes/`, with sub-folders: `post-type/`, `taxonomy/`, `rest/`, `admin/`, `blocks/`.
* When reading `$_POST`, `$_GET`, or `$_REQUEST` variables, always use `filter_var_array()` with appropriate filter constants and validation rules.
* Refer to [10up's PHP Engineering Best Practices](https://10up.github.io/Engineering-Best-Practices/php/) for any other PHP-related decisions.
* Classes will be use the singleton pattern, with an `instance()` method to get the instance.
* Decode JSON to arrays, not objects by default.
* Functions will use type declarations where possible, and return types where possible. Example:
```php
/**
 * Example function with type declarations and return type.
 *
 * @param string $param1 A string parameter.
 * @param int|array $param2 An integer or array parameter.
 * @return array An array return value.
 */
public function my_function( string $param1, int|array $param2 ): array {
    // function code
    return $result;
}
```
