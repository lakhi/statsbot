import { testArmFromPath } from './test-arm';

describe('testArmFromPath', () => {

  it('maps each test URL to its arm', () => {
    expect(testArmFromPath('/rag-pilot-test/no-rag/', true)?.arm).toBe('no_rag');
    expect(testArmFromPath('/rag-pilot-test/non-salient/', true)?.arm).toBe('rag_nonsalient');
    expect(testArmFromPath('/rag-pilot-test/salient/', true)?.arm).toBe('rag_salient');
  });

  it('tolerates a missing trailing slash and an explicit index.html', () => {
    expect(testArmFromPath('/rag-pilot-test/salient', true)?.arm).toBe('rag_salient');
    expect(testArmFromPath('/rag-pilot-test/salient/index.html', true)?.arm).toBe('rag_salient');
  });

  it('pins nothing on the plain pilot URL or the live site', () => {
    expect(testArmFromPath('/rag-pilot-test/', true)).toBeNull();
    expect(testArmFromPath('/', true)).toBeNull();
  });

  it('does not match a slug that merely contains an arm name', () => {
    // "non-salient" must not be read as "salient", nor a prototype key as an arm
    expect(testArmFromPath('/rag-pilot-test/salient-ish/', true)).toBeNull();
    expect(testArmFromPath('/rag-pilot-test/constructor/', true)).toBeNull();
  });

  it('is inert unless the build enables it', () => {
    // the live build has armOverride: false - a student typing /salient/ gets nothing
    expect(testArmFromPath('/rag-pilot-test/salient/', false)).toBeNull();
  });
});
