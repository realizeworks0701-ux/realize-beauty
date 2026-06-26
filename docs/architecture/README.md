# Architecture

Realize Beautyは以下のアーキテクチャで構成する。

Client

↓

Vue3

↓

REST API

↓

Laravel

↓

PostgreSQL

↓

Cloudflare R2

---

## Backend

Laravel 12

Controller

↓

Service

↓

Repository

↓

Model

---

## Frontend

Vue3

↓

Composable

↓

API Client

↓

REST API

---

## AI

OpenAI API

↓

Laravel Service

↓

Database

---

## Authentication

Laravel Sanctum

Token Authentication