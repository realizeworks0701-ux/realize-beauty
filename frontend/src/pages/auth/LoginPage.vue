<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import { useToast } from 'primevue/usetoast'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const route = useRoute()
const toast = useToast()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const submitting = ref(false)

const fieldErrors = reactive<{ email: string; password: string }>({
  email: '',
  password: '',
})

function validate(): boolean {
  fieldErrors.email = email.value.trim() === '' ? 'メールアドレスを入力してください' : ''
  fieldErrors.password = password.value === '' ? 'パスワードを入力してください' : ''
  return fieldErrors.email === '' && fieldErrors.password === ''
}

async function handleSubmit(): Promise<void> {
  if (!validate() || submitting.value) return

  submitting.value = true
  try {
    await auth.login(email.value, password.value)
    const redirect = route.query.redirect
    if (typeof redirect === 'string' && redirect !== '') {
      await router.push(redirect)
    } else {
      await router.push({ name: 'dashboard' })
    }
  } catch {
    toast.add({
      severity: 'error',
      summary: 'ログインに失敗しました',
      detail: 'メールアドレスまたはパスワードが正しくありません',
      life: 4000,
    })
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="login-screen">
    <div class="blob blob-rose" aria-hidden="true" />
    <div class="blob blob-beige" aria-hidden="true" />
    <div class="blob blob-mauve" aria-hidden="true" />

    <main class="glass-card login-card">
      <div class="brand">
        <span class="brand-mark">
          <i class="pi pi-sparkles" />
        </span>
        <h1 class="brand-name">Realize Beauty</h1>
        <p class="brand-caption">
          <i class="pi pi-heart" />
          Salon Management System
        </p>
      </div>

      <form class="login-form" novalidate @submit.prevent="handleSubmit">
        <div class="field">
          <label class="field-label" for="login-email">
            <i class="pi pi-envelope" />
            メールアドレス
          </label>
          <InputText
            id="login-email"
            v-model="email"
            type="email"
            autocomplete="email"
            placeholder="you@example.com"
            fluid
            :invalid="fieldErrors.email !== ''"
            :disabled="submitting"
          />
          <small v-if="fieldErrors.email" class="field-error">
            <i class="pi pi-exclamation-circle" />
            {{ fieldErrors.email }}
          </small>
        </div>

        <div class="field">
          <label class="field-label" for="login-password">
            <i class="pi pi-lock" />
            パスワード
          </label>
          <Password
            v-model="password"
            input-id="login-password"
            :feedback="false"
            toggle-mask
            autocomplete="current-password"
            placeholder="••••••••"
            fluid
            :invalid="fieldErrors.password !== ''"
            :disabled="submitting"
          />
          <small v-if="fieldErrors.password" class="field-error">
            <i class="pi pi-exclamation-circle" />
            {{ fieldErrors.password }}
          </small>
        </div>

        <Button
          type="submit"
          label="ログイン"
          icon="pi pi-sign-in"
          class="login-button"
          :loading="submitting"
          fluid
        />
      </form>
    </main>
  </div>
</template>

<style scoped>
.login-screen {
  position: relative;
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
  overflow: hidden;
}

/* ---------- 装飾ブロブ ---------- */

.blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(60px);
  pointer-events: none;
}

.blob-rose {
  top: -120px;
  left: -100px;
  width: 420px;
  height: 420px;
  background: var(--rb-gradient-rose);
  opacity: 0.24;
}

.blob-beige {
  bottom: -140px;
  right: -120px;
  width: 460px;
  height: 460px;
  background: var(--rb-gradient-peach);
  opacity: 0.24;
}

.blob-mauve {
  top: 40%;
  right: 12%;
  width: 260px;
  height: 260px;
  background: var(--rb-gradient-mauve);
  opacity: 0.24;
}

/* ---------- カード ---------- */

.login-card {
  position: relative;
  z-index: 1;
  width: 100%;
  max-width: 420px;
  padding: 2.6rem 2.2rem 2.4rem;
  box-shadow: 0 16px 48px rgba(90, 70, 150, 0.14);
}

@media (max-width: 480px) {
  .login-card {
    padding: 2.1rem 1.5rem 2rem;
  }
}

/* ---------- ブランド ---------- */

.brand {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.55rem;
  margin-bottom: 2rem;
  text-align: center;
}

.brand-mark {
  display: grid;
  place-items: center;
  width: 64px;
  height: 64px;
  border-radius: 20px;
  background: var(--rb-gradient-brand);
  color: #fff;
  font-size: 1.6rem;
  box-shadow: var(--rb-shadow-brand);
}

.brand-name {
  margin: 0;
  font-family: var(--rb-font-display);
  font-size: 1.75rem;
  font-weight: 700;
  background: var(--rb-gradient-brand);
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
  color: transparent;
}

.brand-caption {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  margin: 0;
  font-size: 0.82rem;
  letter-spacing: 0.08em;
  color: var(--rb-text-muted);
}

.brand-caption i {
  font-size: 0.72rem;
  color: var(--rb-primary);
}

/* ---------- フォーム ---------- */

.login-form {
  display: flex;
  flex-direction: column;
  gap: 1.15rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.field-label {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.85rem;
  font-weight: 700;
  color: var(--rb-text);
}

.field-label i {
  color: var(--rb-primary);
  font-size: 0.8rem;
}

.field-error {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.76rem;
  color: var(--rb-danger);
}

.field-error i {
  font-size: 0.72rem;
}

.login-button {
  margin-top: 0.5rem;
}
</style>
