import SwiftUI

struct ItKnowledgeTriangleShape: Shape {
    var inset: CGFloat = 0
    func path(in rect: CGRect) -> Path {
        let c=CGPoint(x:rect.midX,y:rect.midY), h=rect.height/2
        let raw=[CGPoint(x:rect.minX,y:rect.maxY),CGPoint(x:rect.maxX,y:rect.maxY),CGPoint(x:rect.midX,y:rect.minY)]
        var p=Path(); for (i,v) in raw.enumerated(){let q=CGPoint(x:v.x+(c.x-v.x)*inset,y:v.y+(c.y-v.y)*inset);i==0 ? p.move(to:q):p.addLine(to:q)};p.closeSubpath();return p
    }
}

struct ItKnowledgeMark: View {
    let stage: KnowledgeStage
    var size: CGFloat = 26
    var key: String = "legend"
    @Environment(\.it) private var it
    var body: some View { ZStack {
        let shape=ItKnowledgeTriangleShape(inset:ItKnowledgeGeometry.triangleInset)
        if stage == .recognized { ItKnowledgeLooseLines(key:key).stroke(it.color(.knowledgeDiscovered),style:StrokeStyle(lineWidth:1.25,lineCap:.round)) }
        else if stage == .identified { shape.stroke(it.color(.knowledgeDiscovered),lineWidth:1.25) }
        else { shape.fill(it.color(stage == .understood ? .knowledgeUnderstood:.knowledgeMastered)) }
        if stage == .mastered { ItKnowledgeTriangleShape(inset:0.18).stroke(it.page,lineWidth:0.75) }
    }.frame(width:size,height:size*sqrt(3)/2).accessibilityHidden(true) }
}

/// The final triangle's sides, shortened, translated, and rotated into a repeatable loose state.
struct ItKnowledgeLooseLines: Shape {
    let key:String
    func path(in rect:CGRect)->Path {
        let points=[CGPoint(x:rect.minX,y:rect.maxY),CGPoint(x:rect.maxX,y:rect.maxY),CGPoint(x:rect.midX,y:rect.minY)]
        let seed=key.utf8.reduce(UInt64(1469598103934665603)){($0 ^ UInt64($1)) &* 1099511628211}
        var path=Path()
        for i in 0..<3 {
            let a=points[i],b=points[(i+1)%3],mid=CGPoint(x:(a.x+b.x)/2,y:(a.y+b.y)/2)
            let length:CGFloat=0.68 + CGFloat((seed >> (i*7)) & 255)/255*0.04
            let angleUnit=CGFloat((seed >> (i*11+3)) & 255)/255
            let angle=(angleUnit*8-4)*CGFloat.pi/180
            let offset=CGSize(width:CGFloat(Int((seed >> (i*9+5)) & 7)-3)*rect.width/140,height:CGFloat(Int((seed >> (i*9+8)) & 7)-3)*rect.height/140)
            let dx=(b.x-a.x)*length/2,dy=(b.y-a.y)*length/2,cs=cos(angle),sn=sin(angle)
            let v=CGPoint(x:dx*cs-dy*sn,y:dx*sn+dy*cs),m=CGPoint(x:mid.x+offset.width,y:mid.y+offset.height)
            path.move(to:CGPoint(x:m.x-v.x,y:m.y-v.y));path.addLine(to:CGPoint(x:m.x+v.x,y:m.y+v.y))
        }
        return path
    }
}

struct ItKnowledgeBrain: View {
    let response: KnowledgeResponse
    var loadItems: ((String) async -> [KnowledgeItem])? = nil
    var staticYaw: Double? = nil
    @Environment(\.it) private var it
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var selected: KnowledgeVendor?
    @State private var showList=false
    @State private var visibleStages:[String:KnowledgeStage]=[:]
    @State private var yaw=ItKnowledgeGeometry.restingYaw
    @State private var rotationStart=Date.now
    @State private var dragStart:Double?
    @State private var previousCount:Int?
    @State private var displayedCount:Int?
    @State private var shapeBlend=1.0
    private var vendorCount:Int { max(1,response.items.count) }
    private var summary:String { ItKnowledgeGeometry.summary(response.items) }
    var body: some View { VStack(alignment:.leading,spacing:ItSpace.s4) {
        Text(summary).itText(.body16).foregroundStyle(it.text)
        LazyVGrid(columns:[GridItem(.flexible(),alignment:.leading),GridItem(.flexible(),alignment:.leading)],alignment:.leading,spacing:ItSpace.s2){ForEach(KnowledgeStage.allCases,id:\.self){s in HStack(spacing:6){ItKnowledgeMark(stage:s,size:22);Text(s.title).itText(.caption12).foregroundStyle(it.text)}}}
        if showList { list } else { brain }
    }
    .sheet(item:$selected){ vendor in ItKnowledgeVendorSheet(vendor:vendor,loadItems:loadItems).environment(\.it,it) }
    .onAppear{displayedCount=vendorCount;rotationStart = .now;revealStages()}
    .onChange(of:selected != nil) { _ in rotationStart = .now }
    .onChange(of:vendorCount) { newCount in
        previousCount=displayedCount;displayedCount=newCount;shapeBlend=0
        if reduceMotion { shapeBlend=1;previousCount=nil }
        else { withAnimation(.easeInOut(duration:0.9)){shapeBlend=1};Task{@MainActor in try? await Task.sleep(for:.seconds(0.9));previousCount=nil} }
    }
    }
    private var brain:some View { TimelineView(.animation) { timeline in GeometryReader { proxy in
        let liveYaw=displayedYaw(at:timeline.date)
        let faces=ItKnowledgeGeometry.projectedFaces(count:vendorCount,in:proxy.size,yaw:liveYaw)
        Canvas { context,_ in
            if let previousCount,shapeBlend<1 {
                let old=ItKnowledgeGeometry.projectedFaces(count:max(1,previousCount),in:proxy.size,yaw:liveYaw)
                context.opacity=1-shapeBlend;draw(faces:old,context:&context)
                context.opacity=shapeBlend
            }
            draw(faces:faces,context:&context)
        }
        .contentShape(Rectangle()).gesture(DragGesture(minimumDistance:0).onChanged { value in
            if dragStart == nil { yaw=liveYaw;dragStart=yaw }
            guard let start=dragStart else{return}
            yaw=start+Double(value.translation.width)*0.012
        }.onEnded { value in
            dragStart=nil
            rotationStart = .now
            if hypot(value.translation.width,value.translation.height)<8,
               let slot=ItKnowledgeGeometry.resolvedSlot(at:value.location,faces:faces),
               let vendor=response.items.first(where:{$0.slot==slot}) { selected=vendor }
        })
        .accessibilityElement(children:.ignore).accessibilityLabel(summary)
        .accessibilityAction(named:"Show as list") { showList=true }
    }}.frame(height:300).background(it.surface,in:RoundedRectangle(cornerRadius:ItRadius.lg)) }
    private func displayedYaw(at date:Date)->Double {
        if let staticYaw { return staticYaw }
        if reduceMotion,dragStart == nil { return ItKnowledgeGeometry.mostFrontYaw(count:vendorCount) }
        guard dragStart == nil,selected == nil else { return yaw }
        return yaw+date.timeIntervalSince(rotationStart)/ItKnowledgeGeometry.turnDuration*2*Double.pi
    }
    private var list:some View { VStack(alignment:.leading,spacing:ItSpace.s4){
        Button("Show brain") { showList=false }.buttonStyle(.plain).itText(.body14).foregroundStyle(it.tenant)
        ForEach(KnowledgeStage.allCases.reversed(),id:\.self){stage in let vendors=response.items.filter{$0.stage==stage};if !vendors.isEmpty { VStack(alignment:.leading,spacing:ItSpace.s2){Text(stage.title).itText(.heading16);ForEach(vendors){v in Button{selected=v}label:{HStack{ItKnowledgeMark(stage:v.stage,size:22);Text("\(v.label), \(v.stage.rawValue), \(v.evidence.receiptsApproved) receipts, \(v.evidence.transactionsMatched) matched").itText(.body14).multilineTextAlignment(.leading)}}.buttonStyle(.plain).accessibilityLabel("\(v.label), \(v.stage.rawValue), \(v.evidence.receiptsApproved) receipts, \(v.evidence.transactionsMatched) matched")}}}}
    }}
    private func draw(faces:[ItKnowledgeProjectedFace],context:inout GraphicsContext){
        for face in faces {
            guard let slot=face.slot,let vendor=response.items.first(where:{$0.slot==slot}) else { drawUnlit(face,context:&context);continue }
            draw(vendor:vendor,face:face,context:&context)
        }
    }
    private func path(_ points:[CGPoint],inset:CGFloat=0)->Path { let c=CGPoint(x:points.map(\.x).reduce(0,+)/3,y:points.map(\.y).reduce(0,+)/3);var path=Path();for(i,p)in points.enumerated(){let q=CGPoint(x:p.x+(c.x-p.x)*inset,y:p.y+(c.y-p.y)*inset);i==0 ? path.move(to:q):path.addLine(to:q)};path.closeSubpath();return path }
    private func drawUnlit(_ face:ItKnowledgeProjectedFace,context:inout GraphicsContext){
        let opacity=face.front ? 0.30:0.08
        context.stroke(path(face.points),with:.color(it.color(.knowledgeDiscovered).opacity(opacity)),lineWidth:face.front ? 1:0.6)
    }
    private func draw(vendor:KnowledgeVendor,face:ItKnowledgeProjectedFace,context:inout GraphicsContext){
        let triangle=path(face.points)
        let shown=visibleStages[vendor.key] ?? vendor.stage
        let alpha=face.front ? 1.0:0.16
        if shown == .recognized {
            let seed=vendor.key.utf8.reduce(UInt64(1469598103934665603)){($0 ^ UInt64($1)) &* 1099511628211}
            var loose=Path();for i in 0..<3 {let a=face.points[i],b=face.points[(i+1)%3],mid=CGPoint(x:(a.x+b.x)/2,y:(a.y+b.y)/2);let length=0.66+CGFloat((seed>>(i*7))&255)/255*0.06;let dx=(b.x-a.x)*length/2,dy=(b.y-a.y)*length/2;let offset=CGFloat(Int((seed>>(i*9+5))&7)-3)*0.35;loose.move(to:CGPoint(x:mid.x-dx+offset,y:mid.y-dy-offset));loose.addLine(to:CGPoint(x:mid.x+dx+offset,y:mid.y+dy-offset))}
            context.stroke(loose,with:.color(it.color(.knowledgeDiscovered).opacity(alpha)),style:StrokeStyle(lineWidth:1.25,lineCap:.round))
        } else if shown == .identified { context.stroke(triangle,with:.color(it.color(.knowledgeDiscovered).opacity(alpha)),lineWidth:1.25) }
        else { context.fill(triangle,with:.color(it.color(shown == .understood ? .knowledgeUnderstood:.knowledgeMastered).opacity(alpha))) }
        if shown == .mastered { context.stroke(path(face.points,inset:0.18),with:.color(it.page.opacity(alpha)),lineWidth:0.75) }
    }
    private func revealStages(){var start:[String:KnowledgeStage]=[:];var upgrades=false;for v in response.items{let key="ittai.knowledge.penny.\(v.key)";let saved=UserDefaults.standard.string(forKey:key) ?? "";let old=(saved == "discovered" ? .recognized : KnowledgeStage(rawValue:saved)) ?? v.stage;start[v.key]=old;if v.stage>old{upgrades=true};UserDefaults.standard.set(v.stage.rawValue,forKey:key)};visibleStages=start;guard upgrades,!reduceMotion else{visibleStages=Dictionary(uniqueKeysWithValues:response.items.map{($0.key,$0.stage)});return};Task{@MainActor in for step in [KnowledgeStage.identified,.understood,.mastered]{let duration=step == .identified ? 1.2 : (step == .understood ? 0.6 : 0.8);withAnimation(.easeInOut(duration:duration)){for v in response.items where v.stage >= step && (visibleStages[v.key] ?? v.stage) < step{visibleStages[v.key]=step}};try? await Task.sleep(for:.seconds(duration))}}}
}

struct ItKnowledgeVendorSheet:View {
    let vendor:KnowledgeVendor;let loadItems:((String)async->[KnowledgeItem])?;@Environment(\.it)private var it;@Environment(\.dismiss)private var dismiss;@State private var items:[KnowledgeItem]=[];@State private var loading=false
    var body:some View{NavigationStack{ScrollView{VStack(alignment:.leading,spacing:ItSpace.s5){HStack{ItKnowledgeMark(stage:vendor.stage,size:40);VStack(alignment:.leading){Text(vendor.label).itText(.title22,weight:600);Text(vendor.stage.title).itText(.body14).foregroundStyle(it.meta)}}
        metrics
        section("Open questions"){if vendor.evidence.exceptions.isEmpty{Text("No open questions")}else{ForEach(vendor.evidence.exceptions,id:\.self){Text($0.text)}}}
        if let next=vendor.stage.nextTitle,!vendor.next.isEmpty{section("To reach \(next)"){ForEach(vendor.next,id:\.self){Text($0)}}}
        section("Items"){if loading{ProgressView()}else if items.isEmpty{Text("No item details yet").foregroundStyle(it.meta)}else{ForEach(KnowledgeItemStage.allCases.reversed(),id:\.self){stage in let group=items.filter{$0.stage==stage};if !group.isEmpty{Text(stage.title).itText(.heading16);ForEach(group){item in HStack{ItKnowledgeMark(stage:vendorStage(item.stage),size:22,key:"\(vendor.key):\(item.label)");Text("\(item.label) · seen \(item.timesSeen), confirmed \(item.timesConfirmed)")}.accessibilityElement(children:.combine).accessibilityLabel("\(item.label), \(item.stage.rawValue), seen \(item.timesSeen), confirmed \(item.timesConfirmed)")}}}}}
    }.itText(.body14).foregroundStyle(it.text).padding(ItSpace.s4)}}.background(it.page).navigationTitle("Penny's knowledge").toolbar{ToolbarItem(placement:.confirmationAction){Button("Done"){dismiss()}}}.task{guard let loadItems else{return};loading=true;items=await loadItems(vendor.key);loading=false}}
    private var metrics:some View{VStack(alignment:.leading,spacing:8){row("Receipts processed",vendor.evidence.receiptsApproved);row("Transactions matched",vendor.evidence.transactionsMatched);row("Items recognised",vendor.evidence.itemsRecognized);Text("\(vendor.evidence.itemsNew) items new to Penny");Text(vendor.evidence.classificationAccuracy.map{"\(Int($0*100))% · \(vendor.evidence.classificationDecided) decided"} ?? "Not enough decisions yet");Text("Last verified: \(vendor.evidence.lastVerified ?? "Not verified yet")")}}
    private func row(_ label:String,_ n:Int)->some View{Text("\(label): \(n)")}
    private func section<Content:View>(_ title:String,@ViewBuilder content:()->Content)->some View{VStack(alignment:.leading,spacing:8){Text(title).itText(.heading16);content()}.frame(maxWidth:.infinity,alignment:.leading)}
    private func vendorStage(_ stage:KnowledgeItemStage)->KnowledgeStage { switch stage { case .recognized:.recognized;case .understood:.understood;case .mastered:.mastered } }
}
