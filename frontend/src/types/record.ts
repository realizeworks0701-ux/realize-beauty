import type { Photo } from './photo'

export type RecordStatus = 'draft' | 'completed'

export interface RecordBlock {
  id: number
  label: string
  content: string
  sort_order: number
}

export interface RecordBlockInput {
  id?: number | null
  label: string
  content: string
  sort_order: number
}

export interface RecordCustomerSummary {
  id: number
  name: string
  kana: string
  phone: string | null
}

export interface RecordUserSummary {
  id: number
  name: string
}

/** カルテ（TS組み込みの Record 型と衝突しないよう TreatmentRecord とする） */
export interface TreatmentRecord {
  id: number
  customer: RecordCustomerSummary
  user: RecordUserSummary
  status: RecordStatus
  visited_at: string
  ai_summary: string | null
  blocks?: RecordBlock[]
  photos?: Photo[]
  created_at: string
  updated_at: string
}

export interface RecordCreateInput {
  visited_at: string
  status: RecordStatus
  blocks: RecordBlockInput[]
}

export interface RecordUpdateInput {
  visited_at?: string
  status?: RecordStatus
  blocks?: RecordBlockInput[]
}
