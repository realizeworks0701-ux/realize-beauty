<script setup lang="ts">
import { ref } from 'vue'
import Dialog from 'primevue/dialog'
import type { Photo } from '@/types'

withDefaults(
  defineProps<{
    photos: Photo[]
    removable?: boolean
  }>(),
  {
    removable: false,
  },
)

const emit = defineEmits<{
  remove: [photo: Photo]
}>()

const previewPhoto = ref<Photo | null>(null)
const previewVisible = ref(false)

function openPreview(photo: Photo): void {
  previewPhoto.value = photo
  previewVisible.value = true
}
</script>

<template>
  <div>
    <div class="photo-grid">
      <div v-for="photo in photos" :key="photo.id" class="photo-tile">
        <button type="button" class="photo-open" @click="openPreview(photo)">
          <img :src="photo.url" :alt="photo.caption ?? '写真'" loading="lazy" />
          <span class="photo-overlay">
            <span v-if="photo.caption" class="photo-caption">
              <i class="pi pi-comment" /> {{ photo.caption }}
            </span>
          </span>
        </button>
        <button
          v-if="removable"
          type="button"
          class="photo-remove"
          aria-label="写真を削除"
          @click="emit('remove', photo)"
        >
          <i class="pi pi-trash" />
        </button>
      </div>
      <slot name="append" />
    </div>

    <Dialog
      v-model:visible="previewVisible"
      modal
      dismissable-mask
      :header="previewPhoto?.caption ?? '写真'"
      class="photo-preview-dialog"
    >
      <img
        v-if="previewPhoto"
        :src="previewPhoto.url"
        :alt="previewPhoto.caption ?? '写真'"
        class="photo-preview"
      />
    </Dialog>
  </div>
</template>

<style scoped>
.photo-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
}

@media (min-width: 900px) {
  .photo-grid {
    grid-template-columns: repeat(4, 1fr);
  }
}

.photo-tile {
  position: relative;
  aspect-ratio: 1 / 1;
  border-radius: 12px;
  overflow: hidden;
  background: var(--rb-pink-faint);
}

.photo-open {
  display: block;
  width: 100%;
  height: 100%;
  padding: 0;
  border: none;
  cursor: pointer;
  background: transparent;
}

.photo-open img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.25s ease;
}

.photo-tile:hover .photo-open img {
  transform: scale(1.06);
}

.photo-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: flex-end;
  padding: 0.55rem;
  background: linear-gradient(to top, rgba(75, 66, 71, 0.55), transparent 55%);
  opacity: 0;
  transition: opacity 0.2s ease;
  pointer-events: none;
}

.photo-tile:hover .photo-overlay,
.photo-tile:focus-within .photo-overlay {
  opacity: 1;
}

.photo-caption {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  color: #fff;
  font-size: 0.75rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.photo-remove {
  position: absolute;
  right: 0.55rem;
  bottom: 0.55rem;
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  padding: 0;
  border: none;
  border-radius: 50%;
  cursor: pointer;
  background: rgba(255, 255, 255, 0.9);
  color: var(--rb-pink-deep);
  opacity: 0;
  transition:
    background-color 0.15s ease,
    opacity 0.2s ease;
}

.photo-tile:hover .photo-remove,
.photo-tile:focus-within .photo-remove {
  opacity: 1;
}

.photo-remove:hover {
  background: #fff;
}

.photo-preview {
  max-width: min(80vw, 720px);
  max-height: 70vh;
  display: block;
  border-radius: 12px;
}
</style>
