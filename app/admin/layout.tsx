import { requireRole } from '@/lib/auth';
import { AdminShell } from '@/components/admin/AdminShell';
export const dynamic='force-dynamic';
export default async function AdminLayout({children}:{children:React.ReactNode}){const user=await requireRole('ADMIN','MODERATOR','KNOWLEDGE_EDITOR');return <AdminShell user={user}>{children}</AdminShell>}
