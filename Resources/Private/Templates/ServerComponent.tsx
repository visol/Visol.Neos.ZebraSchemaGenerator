import { ContentComponent, withNode } from '@networkteam/zebra/server';
import { ContextProps } from '@networkteam/zebra';
import { getClassNameByNodeType } from "@/lib/getClassNameByNodeType";
import type { {{interfaceName}} } from "@/lib/nodeTypeInterfaces";

const {{componentName}} = async ({ ctx }: { ctx: ContextProps }) => {
    const node = await withNode(ctx);
    const nodeProperties = node.properties as {{interfaceName}};
    const nodeTypeAsClassName = getClassNameByNodeType(node.nodeType);

    return (
        <ContentComponent ctx={ctx} className={`{{category}}-component ${nodeTypeAsClassName}`}>
            Edit "{{componentName}}" component in "{{serverComponentBasePath}}{{relativePath}}.tsx"
        </ContentComponent>
    );
};

export default {{componentName}};
