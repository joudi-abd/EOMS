import { CopyKey } from '../../core/copy';
import { RequestKind } from '../../data/hr';

export const kindKey: Record<RequestKind, CopyKey> = {
  correction: 'kindCorrection',
  equipment: 'kindEquipment',
  resignation: 'kindResignation',
};

export const kindIcon: Record<RequestKind, string> = {
  correction: 'fa-user-clock',
  equipment: 'fa-toolbox',
  resignation: 'fa-door-open',
};

export const kindEffect: Record<RequestKind, CopyKey> = {
  correction: 'correctionEffect',
  equipment: 'equipmentEffect',
  resignation: 'resignationEffect',
};

export const requestKinds: RequestKind[] = ['correction', 'equipment', 'resignation'];
