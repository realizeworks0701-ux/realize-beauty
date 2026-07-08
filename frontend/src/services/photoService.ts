import { apiClient } from './apiClient'
import type { ApiEnvelope, Photo } from '@/types'

export const photoService = {
  async upload(recordId: number, image: File, caption?: string): Promise<Photo> {
    const formData = new FormData()
    formData.append('image', image)
    if (caption) {
      formData.append('caption', caption)
    }
    const { data } = await apiClient.post<ApiEnvelope<Photo>>(
      `/records/${recordId}/photos`,
      formData,
    )
    return data.data
  },

  async remove(photoId: number): Promise<void> {
    await apiClient.delete(`/photos/${photoId}`)
  },
}
