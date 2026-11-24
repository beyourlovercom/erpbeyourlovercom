import {describe,it,expect} from 'vitest'
import {projectKolShape,validateShapeRequest,recoverShapeSuggestions as recover} from './kolShapeProjection'
import {parseKolQuickOrder} from './kolQuickOrderParser'
const recoverShapeSuggestions=(raw:string,assignments:unknown)=>recover(raw,assignments,parseKolQuickOrder(raw))
describe('zero-value shape projection',()=>{
 it('transmits only finite enums and ordinal positions, never values or labels from text',()=>{
  const raw='Name: Demo Sensitive Person\nAddress: 42 Demo Road\nUnknownSecretLabel: private-note\n+001234567890\n00012\nprivate@example.test\nhttps://example.test/demo_user\nRFC: DEMO-TAX-ID'
  const p=projectKolShape(raw)!
  const serialized=JSON.stringify(p.request)
  expect(validateShapeRequest(p.request)).toBe(true)
  for(const secret of ['Demo','Sensitive','42','UnknownSecretLabel','private-note','001234567890','00012','private@example.test','example.test','demo_user','DEMO-TAX-ID'])expect(serialized).not.toContain(secret)
  expect(p.localValues.get(0)).toBe('Demo Sensitive Person')
 })
 it('rejects arbitrary payloads, extra properties, bad enums and nonordinal indexes',()=>{
  for(const bad of [{lines:[{index:0,label:null,kind:'raw secret'}]},{lines:[{index:0,label:null,kind:'text',value:'secret'}]},{lines:[{index:2,label:null,kind:'text'}]},{lines:[],raw:'secret'}])expect(validateShapeRequest(bad)).toBe(false)
 })
 it('restores only local values for compatible suggestions, never tax or untyped name',()=>{
  const raw='Synthetic Person\n+001234567890\n00012\nRFC: DEMO-ID'
  expect(recoverShapeSuggestions(raw,[{index:0,field:'recipient_name'},{index:1,field:'phone'},{index:2,field:'postal_code'},{index:3,field:'recipient_customs_id'}])).toEqual({phone:'+001234567890',postal_code:'00012'})
 })
 it('never overwrites parsed fields or accepts duplicate and fabricated assignments',()=>{
  const raw='Email: first@example.test\nEmail: other@example.test\n00012'
  expect(recoverShapeSuggestions(raw,[{index:0,field:'email'}])).toEqual({})
  expect(recoverShapeSuggestions(raw,[{index:2,field:'postal_code',value:'invented'}])).toEqual({})
  expect(recoverShapeSuggestions(raw,[{index:2,field:'postal_code'},{index:2,field:'phone'}])).toEqual({})
 })
 it('bounds input size without silently truncating',()=>{
  expect(projectKolShape('x'.repeat(16001))).toBeNull()
  expect(projectKolShape(Array(25).fill('x').join('\n'))).toBeNull()
 })
})
