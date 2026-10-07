import { prisma } from './prisma';

export async function buildMatches(ticket: { domain: string; city: string | null }) {
  const experts = await prisma.expertProfile.findMany({
    where: { isVerified: true, domains: { has: ticket.domain } },
    include: {
      user: true,
      slots: {
        where: { isBooked: false, startsAt: { gt: new Date() } },
        orderBy: { startsAt: 'asc' },
        take: 3,
      },
    },
  });

  return experts
    .map((expert) => {
      let score = 60;
      if (ticket.city && expert.user.city === ticket.city) score += 15;
      if (expert.slots.length > 0) score += 10;
      score += Math.min(expert.yearsExperience, 10);
      score += Math.min(Math.floor(expert.experienceCount / 5), 5);
      return {
        expertProfileId: expert.id,
        expertUserId: expert.userId,
        name: expert.user.name,
        city: expert.user.city,
        headline: expert.headline,
        yearsExperience: expert.yearsExperience,
        experienceCount: expert.experienceCount,
        score,
      };
    })
    .sort((a, b) => b.score - a.score)
    .slice(0, 8);
}
