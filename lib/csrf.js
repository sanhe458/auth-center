/* Auth Center CSRF 自动注入
 * 读取 <meta name="csrf-token">，自动：
 *   1. 为所有 <form method=post> 补 hidden csrf_token
 *   2. 代理 window.fetch：对同源写请求自动加 X-CSRF-Token 头
 * 由 pageHead() 输出 meta 并由本脚本注入，防止跨站请求伪造。
 */
(function () {
  var meta = document.querySelector('meta[name="csrf-token"]');
  var token = meta ? meta.getAttribute('content') : '';
  if (!token) return;

  // 1) 所有 POST 表单补 hidden 字段
  function injectForms() {
    var forms = document.querySelectorAll('form');
    for (var i = 0; i < forms.length; i++) {
      var f = forms[i];
      var m = (f.getAttribute('method') || 'get').toLowerCase();
      if (m !== 'post') continue;
      if (f.querySelector('input[name="csrf_token"]')) continue;
      var h = document.createElement('input');
      h.type = 'hidden'; h.name = 'csrf_token'; h.value = token;
      f.appendChild(h);
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', injectForms);
  } else {
    injectForms();
  }

  // 2) 代理 fetch：同源写请求自动带头
  var origFetch = window.fetch;
  if (typeof origFetch === 'function') {
    window.fetch = function (input, init) {
      init = init || {};
      var method = (init.method || (typeof input === 'object' && input.method) || 'GET').toUpperCase();
      if (['POST', 'PUT', 'DELETE', 'PATCH'].indexOf(method) >= 0) {
        var url = (typeof input === 'string') ? input : (input && input.url) || '';
        var sameOrigin = url === '' || url.charAt(0) === '/' || url.indexOf(location.origin) === 0;
        if (sameOrigin) {
          var headers = new Headers(init.headers || (typeof input === 'object' ? input.headers : undefined) || {});
          if (!headers.has('X-CSRF-Token')) headers.set('X-CSRF-Token', token);
          init.headers = headers;
        }
      }
      return origFetch.call(this, input, init);
    };
  }
})();
