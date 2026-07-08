export type UserRole = 'owner' | 'manager' | 'staff'

export interface User {
  id: number
  name: string
  email: string
  role: UserRole
}
