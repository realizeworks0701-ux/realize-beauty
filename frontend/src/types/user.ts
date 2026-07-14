export type UserRole = 'owner' | 'manager' | 'staff'

export interface User {
  id: number
  name: string
  email: string
  role: UserRole
}

/** GET /users が返す在籍スタッフ（予約の担当者選択用） */
export interface StaffUser {
  id: number
  name: string
  role: UserRole
}
