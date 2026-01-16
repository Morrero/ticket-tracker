# Ticket Tracker (PHP + Docker)

Небольшое backend-приложение для учёта заявок (тикетов) с ролями пользователей и администраторов.
Проект разворачивается через Docker и не требует локальной установки PHP или MySQL.

---

## Стек

* PHP 8.2 (FPM)
* MySQL 8.0
* Nginx
* Docker / Docker Compose
* Чистый PHP без фреймворков

---

## Возможности

* Регистрация и авторизация пользователей
* Роли: `client` и `admin`
* Создание тикетов
* Статусы тикетов
* Комментарии и теги
* REST API на PHP
* Простые HTML-страницы для взаимодействия с API

---

## Запуск проекта

### 1. Требования

* Docker
* Docker Compose

---

### 2. Клонирование

```bash
git clone https://github.com/Morrero/ticket-tracker.git
cd ticket-tracker
```

---

### 3. Настройка окружения

Создай файл окружения:

```bash
cp config/.env.example config/.env
```

При необходимости отредактируй `config/.env`.

---

### 4. Запуск контейнеров

```bash
docker compose up -d --build
```

---

### 5. Доступ к приложению

* Frontend:
  [http://localhost:8080/login.html](http://localhost:8080/login.html)

* API:
  [http://localhost:8080/api.php](http://localhost:8080/api.php)

---

## База данных

При первом запуске MySQL автоматически:

* создаёт базу `ticket_tracker`
* применяет миграции из `docker/mysql/init.sql`

---

