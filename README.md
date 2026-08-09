# Switch Kernel (`switch/kernel`)

> PSR-15 HTTP Application Kernel powering request/response lifecycles, middleware pipelines, and auto-wired package discovery.

---

## 📦 Installation

```bash
composer require switch/kernel
```

---

## 🚀 Usage

```php
use Switch\Kernel\App;

$app = new App($container, router: $router);

// Register custom PSR-15 middleware
$app->use(new AuthMiddleware());

// Process request & emit response
$app->run();
```

---

## 📄 License
MIT License.
