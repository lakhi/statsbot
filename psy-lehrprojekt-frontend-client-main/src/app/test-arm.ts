import { environment } from '../environments/environment';

//Internal test URLs. /rag-pilot-test/salient/ (and /no-rag/, /non-salient/)
//pins the study arm for every message, so one teammate can try all three
//conditions with one account.
//
//Only the rag-pilot build reads the path (environment.armOverride); the live
//build never sends a pinned arm. The backend refuses one anyway unless the
//test stack has STUDY_ARM_OVERRIDE=true and is not in the study phase.
export interface TestArm {
  //value sent as `test_arm`, one of the backend's config('study.arms') keys
  arm: 'no_rag' | 'rag_nonsalient' | 'rag_salient';
  //shown to the tester, so a screenshot says which condition it came from
  label: string;
}

export const TEST_ARMS: Record<string, TestArm> = {
  'no-rag': { arm: 'no_rag', label: 'No-RAG (control)' },
  'non-salient': { arm: 'rag_nonsalient', label: 'Non-salient RAG' },
  'salient': { arm: 'rag_salient', label: 'Salient RAG' },
};

export function testArmFromPath(pathname: string, enabled: boolean = environment.armOverride): TestArm | null {
  if (!enabled) {
    return null;
  }

  const slug = pathname.split('/').find(segment => Object.hasOwn(TEST_ARMS, segment));

  return slug ? TEST_ARMS[slug] : null;
}
