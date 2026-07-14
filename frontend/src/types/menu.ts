export interface MenuSummary {
  id: number
  name: string
  price: number
  duration_minutes: number
  is_active: boolean
}

export interface Menu extends MenuSummary {
  display_order: number
  created_at: string
  updated_at: string
}

export interface MenuCreateInput {
  name: string
  price: number
  duration_minutes: number
  display_order?: number
  is_active?: boolean
}

export interface MenuUpdateInput {
  name?: string
  price?: number
  duration_minutes?: number
  display_order?: number
  is_active?: boolean
}

export interface MenuListParams {
  is_active?: boolean
}
