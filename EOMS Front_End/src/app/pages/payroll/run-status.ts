import { CopyKey } from '../../core/copy';
import { RunStatus } from '../../data/hr';

export const runStatusKey: Record<RunStatus, CopyKey> = {
  draft: 'statusDraft',
  review: 'statusReview',
  approval: 'statusApproval',
  approved: 'statusApproved',
};
